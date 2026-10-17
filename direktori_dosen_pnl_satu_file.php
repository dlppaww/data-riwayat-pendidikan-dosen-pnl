<?php
declare(strict_types=1);

const API_ROOT = 'https://pddikti.kemdiktisaintek.go.id/api';
const PT_MATCH = 'politekniknegerilhokseumawe';
const CACHE_CARI = 86400;
const CACHE_DET = 604800;

class PddiktiException extends RuntimeException
{
    public array $extra;
    public function __construct(string $message, int $status = 502, array $extra = [])
    {
        parent::__construct($message, $status);
        $this->extra = $extra;
    }
}

function normalize(string $v): string
{
    return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim($v), 'UTF-8')) ?? '';
}

function is_list_array(array $a): bool
{
    return $a === [] || array_keys($a) === range(0, count($a) - 1);
}

function records_from(mixed $v, int $depth = 0): array
{
    if ($depth > 6 || !is_array($v))
        return [];
    if (is_list_array($v))
        return array_values(array_filter($v, 'is_array'));
    foreach (['dosen', 'data', 'results', 'result', 'items', 'records', 'content'] as $k) {
        if (array_key_exists($k, $v)) {
            $r = records_from($v[$k], $depth + 1);
            if ($r)
                return $r;
        }
    }
    return [];
}

function scalar_str(mixed $v): string
{
    if (is_string($v) || is_numeric($v))
        return (string) $v;
    if (is_array($v)) {
        foreach (['nama_pt', 'nama', 'name'] as $k) {
            if (isset($v[$k]) && (is_string($v[$k]) || is_numeric($v[$k])))
                return (string) $v[$k];
        }
    }
    return '';
}

function pick(array $rec, array $keys): string
{
    foreach ($keys as $k) {
        if (isset($rec[$k]) && (is_string($rec[$k]) || is_numeric($rec[$k])) && trim((string) $rec[$k]) !== '') {
            return trim((string) $rec[$k]);
        }
    }
    return '';
}

function is_pnl(array $rec): bool
{
    foreach (['nama_pt', 'nama_perguruan_tinggi', 'perguruan_tinggi', 'pt', 'nama_institusi'] as $k) {
        if (array_key_exists($k, $rec)) {
            $n = normalize(scalar_str($rec[$k]));
            if ($n !== '')
                return str_contains($n, PT_MATCH) || $n === 'pnl';
        }
    }
    return false;
}

function find_ca_file(): string
{
    $s = DIRECTORY_SEPARATOR;
    $cands = [
        getenv('PDDIKTI_CAINFO') ?: '',
        __DIR__ . $s . 'cacert.pem',
        (string) ini_get('curl.cainfo'),
        (string) ini_get('openssl.cafile'),
        'C:' . $s . 'laragon' . $s . 'etc' . $s . 'ssl' . $s . 'cacert.pem',
        'C:' . $s . 'xampp' . $s . 'apache' . $s . 'bin' . $s . 'curl-ca-bundle.crt',
        'C:' . $s . 'xampp' . $s . 'php' . $s . 'extras' . $s . 'ssl' . $s . 'cacert.pem',
    ];
    foreach ($cands as $c) {
        if ($c !== '' && is_file($c) && is_readable($c))
            return $c;
    }
    return '';
}

function http_call(string $method, string $url, ?array $json = null): array
{
    if (!function_exists('curl_init')) {
        throw new PddiktiException('Ekstensi cURL belum aktif. Aktifkan extension=curl di php.ini lalu restart server.', 503);
    }
    $ch = curl_init($url);
    $headers = [
        'Accept: application/json',
        'Origin: https://pddikti.kemdiktisaintek.go.id',
        'Referer: https://pddikti.kemdiktisaintek.go.id/',
    ];
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130 Safari/537.36',
    ];
    if (($ca = find_ca_file()) !== '')
        $opt[CURLOPT_CAINFO] = $ca;
    if ($method === 'POST') {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = json_encode($json ?? new stdClass(), JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }
    $opt[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log('PDDikti curl error: ' . $err);
        throw new PddiktiException(
            'Koneksi aman ke PDDikti gagal. Periksa internet dan sertifikat CA (letakkan cacert.pem di folder ini).',
            502,
            ['curl_error' => $err]
        );
    }
    $payload = json_decode((string) $raw, true);
    if ($status < 200 || $status >= 300 || !is_array($payload)) {
        $msg = match (true) {
            $status === 403 => 'PDDikti menolak permintaan (HTTP 403).',
            $status === 429, $status === 503 => 'PDDikti sedang membatasi permintaan. Coba lagi beberapa saat.',
            $status >= 200 && $status < 300 => 'PDDikti mengirim respons yang tidak dapat dibaca.',
            default => 'PDDikti gagal memproses permintaan (HTTP ' . $status . ').',
        };
        throw new PddiktiException($msg, 502, ['upstream_status' => $status, 'response_preview' => mb_substr((string) $raw, 0, 400)]);
    }
    return $payload;
}

/** Jalankan permintaan JSON secara paralel dalam batch kecil agar ekspor lebih cepat. */
function http_multi_json(array $requests, int $concurrency = 12): array
{
    if (!function_exists('curl_multi_init')) throw new PddiktiException('Ekstensi cURL multi belum aktif.', 503);
    $results = [];
    foreach (array_chunk($requests, max(1, $concurrency), true) as $batch) {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($batch as $key => $request) {
            $ch = curl_init($request['url']);
            $headers = ['Accept: application/json', 'Origin: https://pddikti.kemdiktisaintek.go.id', 'Referer: https://pddikti.kemdiktisaintek.go.id/'];
            $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130 Safari/537.36'];
            if (($ca = find_ca_file()) !== '') $opts[CURLOPT_CAINFO] = $ca;
            if (($request['method'] ?? 'GET') === 'POST') {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_POSTFIELDS] = json_encode($request['json'] ?? new stdClass(), JSON_UNESCAPED_SLASHES);
                $headers[] = 'Content-Type: application/json';
            }
            $opts[CURLOPT_HTTPHEADER] = $headers;
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($multi, $ch);
            $handles[spl_object_id($ch)] = ['key' => $key, 'handle' => $ch];
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) curl_multi_select($multi, 1.0);
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $item) {
            $ch = $item['handle'];
            $key = $item['key'];
            $raw = curl_multi_getcontent($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $payload = is_string($raw) ? json_decode($raw, true) : null;
            if ($raw === false || $error !== '') $results[$key] = ['error' => 'Koneksi gagal: ' . $error];
            elseif ($http < 200 || $http >= 300 || !is_array($payload)) $results[$key] = ['error' => 'PDDikti mengembalikan HTTP ' . $http];
            else $results[$key] = ['data' => $payload];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
    }
    return $results;
}

/* ---------- cache file ---------- */
function cache_dir(): string
{
    static $d = null;
    if ($d === null) {
        $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dosen-pnl-cache';
        if (!is_dir($d))
            @mkdir($d, 0700, true);
    }
    return $d;
}

function cache_get(string $key, int $ttl): ?array
{
    $f = cache_dir() . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    if (is_file($f) && filemtime($f) > time() - $ttl) {
        $d = json_decode((string) @file_get_contents($f), true);
        if (is_array($d))
            return $d;
    }
    return null;
}

function cache_put(string $key, array $data): void
{
    if (is_writable(cache_dir())) {
        @file_put_contents(
            cache_dir() . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json',
            json_encode($data, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}

/* Dosen PNL yang pernah muncul di pencarian: id => data ringkas. */
function allowed_map(): array
{
    $d = json_decode((string) @file_get_contents(cache_dir() . DIRECTORY_SEPARATOR . 'allowed.json'), true);
    return is_array($d) ? $d : [];
}

function allow_dosen(array $hasil): void
{
    $map = allowed_map();
    foreach ($hasil as $h)
        $map[$h['id']] = $h;
    if (is_writable(cache_dir())) {
        @file_put_contents(
            cache_dir() . DIRECTORY_SEPARATOR . 'allowed.json',
            json_encode($map, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}

/** Pakai ejaan nama lengkap untuk memastikan ekspor hanya berisi dosen PNL. */
function dosen_pnl_dari_hasil(array $records): array
{
    $hasil = [];
    foreach ($records as $rec) {
        if (!is_pnl($rec)) continue;
        $id = pick($rec, ['id', 'id_dosen', 'id_sdm', 'id_person']);
        $nama = pick($rec, ['nama', 'nama_dosen']);
        if ($id === '' || $nama === '') continue;
        $hasil[$id] = [
            'id' => $id, 'nama' => $nama,
            'nidn' => pick($rec, ['nidn']), 'nuptk' => pick($rec, ['nuptk']),
            'nama_prodi' => pick($rec, ['nama_prodi', 'prodi']),
        ];
    }
    return $hasil;
}

/* ---------- operasi utama ---------- */

/** Cari dosen PNL berdasarkan nama. */
function cari_dosen(string $keyword): array
{
    $len = mb_strlen($keyword);
    if ($len < 3 || $len > 120 || preg_match('/[\x00-\x1F\x7F]/', $keyword)) {
        throw new PddiktiException('Masukkan nama dosen minimal 3 karakter.', 400);
    }
    $ckey = 'cari:' . mb_strtolower($keyword, 'UTF-8');
    if (($hit = cache_get($ckey, CACHE_CARI)) !== null)
        return $hit;

    $urls = [
        API_ROOT . '/pencarian/dosen/' . rawurlencode($keyword),
        API_ROOT . '/pencarian/en/call/' . rawurlencode($keyword),
    ];
    $records = null;
    $lastErr = null;
    foreach ($urls as $u) {
        try {
            $records = records_from(http_call('GET', $u));
            if ($records)
                break;
        } catch (PddiktiException $e) {
            $lastErr = $e;
        }
    }
    if ($records === null)
        throw $lastErr ?? new PddiktiException('Pencarian gagal.');

    $hasil = [];
    foreach ($records as $rec) {
        if (!is_pnl($rec))
            continue;
        $id = pick($rec, ['id', 'id_dosen', 'id_sdm', 'id_person']);
        $nama = pick($rec, ['nama', 'nama_dosen']);
        if ($id === '' || $nama === '')
            continue;
        $hasil[] = [
            'id' => $id,
            'nama' => $nama,
            'nidn' => pick($rec, ['nidn']),
            'nuptk' => pick($rec, ['nuptk']),
            'nama_prodi' => pick($rec, ['nama_prodi', 'prodi']),
        ];
    }
    allow_dosen($hasil);
    cache_put($ckey, $hasil);
    return $hasil;
}

/** Ambil seluruh daftar dosen PNL dari tabel data resmi kampus (312 baris saat ini). */
function semua_dosen_pnl(): array
{
    if (!function_exists('curl_init')) {
        throw new PddiktiException('Ekstensi cURL belum aktif untuk mengambil data portal PNL.', 503);
    }
    $ch = curl_init('https://data.pnl.ac.id/dosen/list');
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
    ];
    if (($ca = find_ca_file()) !== '') $options[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $options);
    $html = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if (!is_string($html) || $status < 200 || $status >= 300) {
        error_log('Portal data PNL error: HTTP ' . $status . ' ' . $error);
        throw new PddiktiException('Daftar dosen portal PNL tidak dapat diakses. Periksa koneksi server atau coba beberapa saat lagi.', 502);
    }

    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($doc);
    $rows = $xpath->query('//table//tbody/tr');
    if (!$rows || $rows->length === 0) {
        throw new PddiktiException('Format daftar dosen portal PNL berubah; data tidak diekspor agar hasil tidak keliru.', 502);
    }

    $result = [];
    foreach ($rows as $row) {
        $cells = $xpath->query('./td', $row);
        if (!$cells || $cells->length < 11) continue;
        $values = [];
        foreach ($cells as $cell) $values[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent) ?? '');
        $nama = $values[1] ?? '';
        if ($nama === '' || !preg_match('/\d+/', $values[0] ?? '')) continue;
        $key = $values[2] !== '' ? 'nidn:' . $values[2] : ($values[3] !== '' ? 'nuptk:' . $values[3] : 'nama:' . normalize($nama));
        $row = [
            'no' => $values[0], 'nama' => $nama, 'nidn' => $values[2], 'nuptk' => $values[3],
            'jenjang' => $values[4], 'status' => $values[5], 'status_pegawai' => $values[6],
            'ikatan_kerja' => $values[7], 'prodi' => $values[8], 'jurusan' => $values[9],
            'semester' => $values[10],
        ];
        if (isset($result[$key])) {
            foreach (['prodi', 'jurusan'] as $field) {
                $parts = array_filter(array_map('trim', explode(' | ', $result[$key][$field] . ' | ' . $row[$field])));
                $result[$key][$field] = implode(' | ', array_unique($parts));
            }
        } else $result[$key] = $row;
    }
    if (!$result) throw new PddiktiException('Daftar dosen PNL kosong atau tidak dikenali.', 502);
    return array_values($result);
}

/** Buat unduhan CSV UTF-8 yang dapat dibuka langsung oleh Excel. */
function unduh_excel_pendidikan(): never
{
    set_time_limit(0);
    $dosen = semua_dosen_pnl();
    $searches = [];
    $pendingSearches = [];
    foreach ($dosen as $i => $d) {
        $keyword = $d['nidn'] !== '' ? $d['nidn'] : $d['nama'];
        $cacheKey = 'cari:' . mb_strtolower($keyword, 'UTF-8');
        $cached = cache_get($cacheKey, CACHE_CARI);
        if ($cached !== null) $searches[$i] = $cached;
        else $pendingSearches[$i] = ['url' => API_ROOT . '/pencarian/dosen/' . rawurlencode($keyword)];
    }
    $responses = http_multi_json($pendingSearches);
    $retrySearches = [];
    foreach ($responses as $i => $response) {
        if (isset($response['error'])) $retrySearches[$i] = ['url' => API_ROOT . '/pencarian/en/call/' . rawurlencode($dosen[$i]['nidn'] ?: $dosen[$i]['nama'])];
        else {
            $searches[$i] = array_values(dosen_pnl_dari_hasil(records_from($response['data'])));
            cache_put('cari:' . mb_strtolower($dosen[$i]['nidn'] ?: $dosen[$i]['nama'], 'UTF-8'), $searches[$i]);
        }
    }
    foreach (http_multi_json($retrySearches) as $i => $response) {
        $searches[$i] = isset($response['data']) ? array_values(dosen_pnl_dari_hasil(records_from($response['data']))) : [];
        cache_put('cari:' . mb_strtolower($dosen[$i]['nidn'] ?: $dosen[$i]['nama'], 'UTF-8'), $searches[$i]);
    }

    $ids = [];
    $allow = [];
    foreach ($dosen as $i => $d) {
        foreach ($searches[$i] ?? [] as $found) {
            $sameNidn = $d['nidn'] !== '' && $found['nidn'] === $d['nidn'];
            $sameName = normalize($found['nama']) === normalize($d['nama']);
            if ($sameNidn || $sameName) { $ids[$i] = $found['id']; $allow[] = $found; break; }
        }
    }
    allow_dosen($allow);

    $histories = [];
    $pendingHistories = [];
    foreach ($ids as $i => $id) {
        $cached = cache_get('pendidikan:' . $id, CACHE_DET);
        if ($cached !== null) $histories[$i] = $cached;
        else $pendingHistories[$i] = ['url' => API_ROOT . '/dosen/study-history', 'method' => 'POST', 'json' => ['id' => $id]];
    }
    foreach (http_multi_json($pendingHistories) as $i => $response) {
        if (!isset($response['data'])) continue;
        $histories[$i] = $response['data'];
        cache_put('pendidikan:' . $ids[$i], $response['data']);
    }

    $rowsOut = [];
    foreach ($dosen as $i => $d) {
        $levels = ['S1' => ['kampus' => [], 'tahun' => []], 'S2' => ['kampus' => [], 'tahun' => []], 'S3' => ['kampus' => [], 'tahun' => []]];
        foreach (records_from($histories[$i] ?? []) as $rec) {
            $p = normalisasi_pendidikan($rec);
            $label = mb_strtoupper($p['jenjang'], 'UTF-8');
            $level = match (true) {
                preg_match('/(^|[^0-9])S\s*1([^0-9]|$)|SARJANA|DIPLOMA\s*IV|D\s*4/u', $label) === 1 => 'S1',
                preg_match('/(^|[^0-9])S\s*2([^0-9]|$)|MAGISTER|MASTER/u', $label) === 1 => 'S2',
                preg_match('/(^|[^0-9])S\s*3([^0-9]|$)|DOKTOR|DOCTOR/u', $label) === 1 => 'S3',
                default => null,
            };
            if ($level === null) continue;
            if ($p['pt'] !== '') $levels[$level]['kampus'][$p['pt']] = true;
            if ($p['tahun'] !== '') $levels[$level]['tahun'][$p['tahun']] = true;
        }
        $hasHistory = $levels['S1']['kampus'] || $levels['S2']['kampus'] || $levels['S3']['kampus'];
        $status = !isset($ids[$i]) ? 'Tidak ditemukan di PDDikti' : (!isset($histories[$i]) ? 'Riwayat gagal dimuat' : ($hasHistory ? 'Riwayat tersedia' : 'Riwayat kosong/tidak dikenali'));
        $join = static fn(array $values): string => implode(' | ', array_keys($values));
        $rowsOut[] = [$d['no'], $d['nama'], $d['nidn'], $d['nuptk'], $join($levels['S1']['kampus']), $join($levels['S1']['tahun']), $join($levels['S2']['kampus']), $join($levels['S2']['tahun']), $join($levels['S3']['kampus']), $join($levels['S3']['tahun']), $d['prodi'], $d['jurusan'], $d['status'], $status];
    }

    $namaFile = 'rekap-pendidikan-dosen-pnl-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $namaFile . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['No', 'Nama Dosen', 'NIDN', 'NUPTK', 'S1 - Kampus', 'S1 - Tahun Lulus', 'S2 - Kampus', 'S2 - Tahun Lulus', 'S3 - Kampus', 'S3 - Tahun Lulus', 'Program Studi', 'Jurusan', 'Status Aktif', 'Status Riwayat PDDikti'], ';');
    foreach ($rowsOut as $row) {
        $safe = static fn(string $v): string => preg_match('/^[=+@\-\t\r]/', $v) ? "'" . $v : $v;
        fputcsv($out, array_map($safe, $row), ';');
    }
    fclose($out);
    exit;
}

/** Ambil detail: 'profil' | 'pendidikan' | 'mengajar' (hanya untuk dosen PNL hasil pencarian). */
function detail_dosen(string $aksi, string $id): array
{
    static $routes = [
    'profil' => '/dosen/profile',
    'pendidikan' => '/dosen/study-history',
    'mengajar' => '/dosen/teaching-history',
    ];
    if (!isset($routes[$aksi]))
        throw new PddiktiException('Aksi tidak dikenal.', 400);
    if ($id === '' || strlen($id) > 200 || preg_match('/[\x00-\x20\x7F]/', $id)) {
        throw new PddiktiException('ID dosen tidak valid.', 400);
    }
    if (!isset(allowed_map()[$id])) {
        throw new PddiktiException('Detail hanya tersedia untuk dosen PNL yang ditemukan lewat pencarian.', 403);
    }
    $ckey = $aksi . ':' . $id;
    if (($hit = cache_get($ckey, CACHE_DET)) !== null)
        return $hit;

    $payload = http_call('POST', API_ROOT . $routes[$aksi], ['id' => $id]);
    cache_put($ckey, $payload);
    return $payload;
}

/* ---------- normalisasi untuk tampilan ---------- */

function normalisasi_pendidikan(array $rec): array
{
    return [
        'jenjang' => pick($rec, ['jenjang', 'nama_jenjang_didik', 'jenjang_pendidikan', 'nama_jenjang']),
        'gelar' => pick($rec, ['gelar', 'singkatan_gelar', 'nama_gelar']),
        'bidang' => pick($rec, ['bidang', 'bidang_studi', 'nama_prodi', 'program_studi', 'prodi']),
        'pt' => pick($rec, ['nama_pt', 'perguruan_tinggi', 'nama_perguruan_tinggi']),
        'tahun' => pick($rec, ['tahun_lulus', 'tahun', 'tgl_lulus']),
    ];
}

function normalisasi_mengajar(array $rec): array
{
    return [
        'mk' => pick($rec, ['nama_mk', 'nama_mata_kuliah', 'mata_kuliah']),
        'prodi' => pick($rec, ['nama_prodi', 'prodi']),
        'semester' => pick($rec, ['nama_semester', 'semester', 'id_smt']),
        'sks' => pick($rec, ['sks']),
    ];
}

if (isset($_GET['unduh']) && $_GET['unduh'] === 'excel') {
    try {
        unduh_excel_pendidikan();
    } catch (PddiktiException $e) {
        http_response_code($e->getCode() ?: 502);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Unduhan gagal: ' . $e->getMessage();
        exit;
    }
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$q = trim((string) ($_GET['q'] ?? ''));
$id = trim((string) ($_GET['id'] ?? ''));

$view = 'home';
$error = null;
$hasil = [];
$dosen = null;
$pend = [];
$meng = [];
$errPend = null;
$errMeng = null;

try {
    if ($id !== '') {
        $view = 'detail';
        // Pastikan ID dikenal; kalau cache hilang, minta pengguna mencari ulang.
        $dosen = allowed_map()[$id] ?? null;
        if ($dosen === null) {
            $view = 'home';
            $error = 'Sesi pencarian sudah kedaluwarsa. Silakan cari nama dosen kembali.';
        } else {
            try {
                $pend = records_from(detail_dosen('pendidikan', $id));
            } catch (PddiktiException $e) {
                $errPend = $e->getMessage();
            }
            try {
                $meng = records_from(detail_dosen('mengajar', $id));
            } catch (PddiktiException $e) {
                $errMeng = $e->getMessage();
            }
        }
    } elseif ($q !== '') {
        $hasil = cari_dosen($q);
    }
} catch (PddiktiException $e) {
    $error = $e->getMessage();
}

/** Urutkan riwayat pendidikan dari yang terbaru. */
usort($pend, static function (array $a, array $b): int {
    $ya = (int) preg_replace('/\D/', '', normalisasi_pendidikan($a)['tahun']);
    $yb = (int) preg_replace('/\D/', '', normalisasi_pendidikan($b)['tahun']);
    return $yb <=> $ya;
});

/** Cadangan bila nama field tidak dikenali: tampilkan pasangan kunci-nilai skalar. */
function fallback_pairs(array $rec): array
{
    $out = [];
    foreach ($rec as $k => $v) {
        if ((is_string($v) || is_numeric($v)) && trim((string) $v) !== '') {
            $out[ucfirst(str_replace('_', ' ', (string) $k))] = trim((string) $v);
        }
    }
    return $out;
}

/** Ratakan satu tingkat: nilai skalar dipertahankan, array bersarang jadi "induk_anak". */
function flatten_scalars(array $rec): array
{
    $out = [];
    foreach ($rec as $k => $v) {
        if (is_string($v) || is_numeric($v) || is_bool($v)) {
            $out[(string) $k] = is_bool($v) ? ($v ? 'Ya' : 'Tidak') : trim((string) $v);
        } elseif (is_array($v) && !is_list_array($v)) {
            foreach ($v as $k2 => $v2) {
                if (is_string($v2) || is_numeric($v2))
                    $out[$k . '_' . $k2] = trim((string) $v2);
            }
        }
    }
    return $out;
}

function label_kolom(string $key): string
{
    static $map = [
    'nama_mk' => 'Mata kuliah',
    'nama_mata_kuliah' => 'Mata kuliah',
    'mata_kuliah' => 'Mata kuliah',
    'nm_mk' => 'Mata kuliah',
    'nama_matkul' => 'Mata kuliah',
    'matkul' => 'Mata kuliah',
    'kode_mk' => 'Kode',
    'kode_mata_kuliah' => 'Kode',
    'kd_mk' => 'Kode',
    'nama_kelas' => 'Kelas',
    'kelas' => 'Kelas',
    'nm_kls' => 'Kelas',
    'nama_kls' => 'Kelas',
    'nama_prodi' => 'Prodi',
    'prodi' => 'Prodi',
    'program_studi' => 'Prodi',
    'nama_pt' => 'Perguruan tinggi',
    'sks' => 'SKS',
    'sks_mk' => 'SKS',
    'jml_mhs' => 'Mahasiswa',
    'jumlah_mahasiswa' => 'Mahasiswa',
    ];
    return $map[$key] ?? ucfirst(trim(str_replace('_', ' ', $key)));
}

function is_semester_key(string $k): bool
{
    return (bool) preg_match('/semester|(^|_)smt($|_)/i', $k);
}

function looks_like_id(string $v): bool
{
    return (bool) (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $v) || (strlen($v) >= 24 && !str_contains($v, ' ')));
}

/**
 * Susun riwayat mengajar: kelompokkan per semester, kolom ditentukan otomatis dari data.
 * @return array{groups: array<string, list<array<string,string>>>, cols: list<string>}
 */
function susun_mengajar(array $records): array
{
    $rows = array_map('flatten_scalars', $records);

    // Kunci semester (pilih yang nilainya berupa teks seperti "2025/2026 Ganjil" bila ada)
    $semKey = null;
    foreach ($rows as $r) {
        foreach ($r as $k => $v) {
            if (is_semester_key($k) && $v !== '') {
                if ($semKey === null || (preg_match('/[A-Za-z]/', $v) && !preg_match('/[A-Za-z]/', $r[$semKey] ?? '')))
                    $semKey = $k;
            }
        }
        if ($semKey !== null)
            break;
    }

    // Kolom: semua kunci yang punya isi, tanpa kolom semester dan kolom ID teknis
    $cols = [];
    foreach ($rows as $r) {
        foreach ($r as $k => $v) {
            if ($v === '' || is_semester_key($k))
                continue;
            $cols[$k] ??= ['n' => 0, 'id' => 0];
            $cols[$k]['n']++;
            if (looks_like_id($v))
                $cols[$k]['id']++;
        }
    }
    $cols = array_keys(array_filter($cols, static fn(array $c): bool => $c['id'] < $c['n']));

    // Urutan kolom: kode, mata kuliah, kelas, prodi, lalu SKS dan sisanya
    usort($cols, static function (string $a, string $b): int {
        $rank = static function (string $k): int {
            return match (true) {
                stripos($k, 'sks') !== false => 8,
                (bool) preg_match('/jml|jumlah/i', $k) => 9,
                stripos($k, 'kode') !== false || stripos($k, 'kd_') === 0 => 0,
                (bool) preg_match('/mata|matkul|(^|_)mk($|_)/i', $k) => 1,
                (bool) preg_match('/kelas|kls/i', $k) => 2,
                stripos($k, 'prodi') !== false => 3,
                default => 5,
            };
        };
        return $rank($a) <=> $rank($b);
    });

    $groups = [];
    foreach ($rows as $r) {
        $g = $semKey !== null && ($r[$semKey] ?? '') !== '' ? $r[$semKey] : 'Semester tidak diketahui';
        $groups[$g][] = $r;
    }
    return ['groups' => $groups, 'cols' => $cols];
}

$mengSusun = susun_mengajar($meng);
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Riwayat Dosen PNL<?= $dosen ? ' · ' . h($dosen['nama']) : '' ?></title>
    <style>
:root {
  color-scheme: light;
  --bg: #ffffff;
  --ink: #1d1b18;
  --mute: #837d73;
  --line: #e4dfd5;
  --accent: #1f4d3f;
  --soft: #f1ede4;
  --serif:
    "Iowan Old Style", "Palatino Linotype", Palatino, Georgia,
    "Times New Roman", serif;
  --sans:
    -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
}
* {
  box-sizing: border-box;
}
html {
  -webkit-text-size-adjust: 100%;
}
body {
  margin: 0;
  background: #ffffff;
  background: var(--bg);
  color: var(--ink);
  font: 16px/1.65 var(--sans);
}
a {
  color: inherit;
  text-decoration: none;
}
::selection {
  background: var(--accent);
  color: var(--bg);
}

.page {
  max-width: 720px;
  margin: 0 auto;
  padding: 0 24px;
}
header.top {
  padding: 28px 0 0;
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  font-size: 0.78rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--mute);
}
header.top a:hover {
  color: var(--ink);
}

.hero {
  padding: 96px 0 40px;
}
.hero h1 {
  font: 400 clamp(2.1rem, 6vw, 3.1rem)/1.12 var(--serif);
  letter-spacing: -0.01em;
  margin: 0 0 14px;
}
.hero p {
  margin: 0;
  color: var(--mute);
  max-width: 46ch;
}
body.is-detail .hero {
  display: none;
}
body.is-results .hero {
  padding: 56px 0 28px;
}

form.search {
  position: relative;
  margin: 0 0 8px;
}
form.search input {
  width: 100%;
  padding: 14px 96px 14px 0;
  font: 400 1.15rem var(--serif);
  color: var(--ink);
  background: transparent;
  border: 0;
  border-bottom: 1px solid var(--ink);
  border-radius: 0;
  outline: none;
}
form.search input::placeholder {
  color: var(--mute);
  font-style: italic;
}
form.search input:focus {
  border-bottom-color: var(--accent);
  box-shadow: 0 1px 0 var(--accent);
}
form.search button {
  position: absolute;
  right: 0;
  bottom: 10px;
  padding: 4px 0;
  background: none;
  border: 0;
  cursor: pointer;
  font: 500 0.78rem var(--sans);
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--accent);
}
form.search button:hover {
  text-decoration: underline;
  text-underline-offset: 4px;
}

.export-box {
  margin-top: 26px;
  padding: 16px 0 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 20px;
}
.export-box strong { font: 400 1rem var(--serif); }
.export-box p { margin: 4px 0 0; color: var(--mute); font-size: .88rem; }
.export-button {
  flex: 0 0 auto;
  padding: 4px 0;
  border: 0;
  background: none;
  color: var(--accent);
  font: 500 .78rem var(--sans);
  letter-spacing: .14em;
  text-transform: uppercase;
}
.export-button:hover { text-decoration: underline; text-underline-offset: 4px; }

.note {
  margin: 28px 0;
  padding: 12px 16px;
  border-left: 2px solid var(--accent);
  background: var(--soft);
  color: var(--ink);
  font-size: 0.92rem;
}

.count {
  margin: 36px 0 4px;
  font-size: 0.78rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--mute);
}
ul.list {
  list-style: none;
  margin: 0;
  padding: 0;
}
ul.list li {
  border-bottom: 1px solid var(--line);
}
ul.list a {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 16px;
  padding: 18px 0;
}
ul.list .nama {
  font: 400 1.25rem/1.3 var(--serif);
}
ul.list .info {
  color: var(--mute);
  font-size: 0.88rem;
  margin-top: 2px;
}
ul.list .arrow {
  color: var(--mute);
  transition:
    transform 0.2s,
    color 0.2s;
}
ul.list a:hover .arrow {
  transform: translateX(4px);
  color: var(--accent);
}
ul.list a:hover .nama {
  color: var(--accent);
}

.back {
  display: inline-block;
  margin: 40px 0 0;
  font-size: 0.78rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--mute);
}
.back:hover {
  color: var(--ink);
}
.profile {
  padding: 28px 0 36px;
  border-bottom: 1px solid var(--line);
}
.profile h1 {
  font: 400 clamp(1.9rem, 5vw, 2.6rem)/1.15 var(--serif);
  margin: 0 0 12px;
  letter-spacing: -0.01em;
}
.facts {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 28px;
  color: var(--mute);
  font-size: 0.9rem;
}
.facts b {
  color: var(--ink);
  font-weight: 500;
}

section.block {
  padding: 40px 0 0;
}
section.block h2 {
  margin: 0 0 22px;
  font: 500 0.78rem var(--sans);
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--mute);
}

ol.timeline {
  list-style: none;
  margin: 0;
  padding: 0 0 0 22px;
  border-left: 1px solid var(--line);
}
ol.timeline li {
  position: relative;
  padding: 0 0 30px;
}
ol.timeline li::before {
  content: "";
  position: absolute;
  left: -27px;
  top: 0.6em;
  width: 9px;
  height: 9px;
  border-radius: 50%;
  background: var(--bg);
  border: 1px solid var(--accent);
}
ol.timeline li:first-child::before {
  background: var(--accent);
}
.year {
  font-size: 0.82rem;
  color: var(--accent);
  letter-spacing: 0.06em;
  font-variant-numeric: tabular-nums;
}
.jenjang {
  font: 400 1.3rem/1.3 var(--serif);
  margin: 2px 0;
}
.jenjang small {
  font: 400 0.95rem var(--sans);
  color: var(--mute);
}
.pt {
  color: var(--ink);
}
.bidang {
  color: var(--mute);
  font-size: 0.92rem;
}
dl.kv {
  margin: 0;
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 2px 16px;
  font-size: 0.9rem;
}
dl.kv dt {
  color: var(--mute);
}
dl.kv dd {
  margin: 0;
}

table.mk {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.92rem;
}
table.mk th {
  text-align: left;
  font: 500 0.72rem var(--sans);
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--mute);
  padding: 0 8px 10px 0;
  border-bottom: 1px solid var(--ink);
}
table.mk td {
  padding: 11px 8px 11px 0;
  border-bottom: 1px solid var(--line);
  vertical-align: top;
}
table.mk td.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
  padding-right: 0;
}
table.mk th.num {
  text-align: right;
  padding-right: 0;
}
.empty {
  color: var(--mute);
  font-style: italic;
}
.scroll {
  overflow-x: auto;
}

footer {
  margin-top: 88px;
  padding: 24px 0 48px;
  border-top: 1px solid var(--line);
  color: var(--mute);
  font-size: 0.78rem;
}
@media (max-width: 520px) {
  .page {
    padding: 0 18px;
  }
  .hero {
    padding-top: 64px;
  }
  .export-box { align-items: flex-start; flex-direction: column; }
}

/* Riwayat mengajar: per semester */
.semester {
  margin: 0 0 34px;
}
.semester h3 {
  margin: 0 0 12px;
  font: 400 1.15rem/1.3 var(--serif);
}
.badge {
  display: inline-block;
  margin-left: 8px;
  padding: 1px 9px;
  border: 1px solid var(--line);
  border-radius: 999px;
  font: 500 0.72rem var(--sans);
  letter-spacing: 0.04em;
  color: var(--mute);
  vertical-align: middle;
  text-transform: none;
}
section.block h2 .badge {
  letter-spacing: 0.02em;
}
table.mk td .empty {
  font-style: normal;
  opacity: 0.6;
}
details.raw {
  margin-top: 24px;
  font-size: 0.8rem;
  color: var(--mute);
}
details.raw pre {
  overflow-x: auto;
  padding: 12px;
  background: var(--soft);
  border: 1px solid var(--line);
  color: var(--ink);
}

</style>
</head>

<body class="<?= $view === 'detail' ? 'is-detail' : ($q !== '' ? 'is-results' : '') ?>">
    <div class="page">

        <header class="top">
            <a href="./">Politeknik Negeri Lhokseumawe</a>
            <span>Direktori Dosen</span>
        </header>

        <?php if ($view === 'home'): ?>
            <div class="hero">
                <h1>Riwayat pendidikan<br>dosen PNL</h1>
                <p>Telusuri jenjang, kampus, dan tahun kelulusan dosen Politeknik Negeri Lhokseumawe.</p>
            </div>

            <form class="search" method="get" action="">
                <input type="search" name="q" value="<?= h($q) ?>" placeholder="Ketik nama dosen…" minlength="3" required
                    autocomplete="off" <?= $q === '' ? 'autofocus' : '' ?>>
                <button type="submit">Cari</button>
            </form>

            <div class="export-box">
                <div>
                    <strong>Rekap pendidikan seluruh dosen</strong>
                    <p>Unduh rekap semua dosen PNL dengan kolom kampus dan tahun lulus S1, S2, serta S3.</p>
                </div>
                <a class="export-button" href="?unduh=excel">Unduh Excel</a>
            </div>

            <?php if ($error): ?>
                <div class="note"><?= h($error) ?></div>
            <?php elseif ($q !== ''): ?>
                <?php if (!$hasil): ?>
                    <p class="empty" style="margin-top:32px">Tidak ditemukan dosen PNL dengan nama “<?= h($q) ?>”.</p>
                <?php else: ?>
                    <div class="count"><?= count($hasil) ?> dosen ditemukan</div>
                    <ul class="list">
                        <?php foreach ($hasil as $d):
                            $info = array_filter([$d['nama_prodi'], $d['nidn'] !== '' ? 'NIDN ' . $d['nidn'] : '']); ?>
                            <li>
                                <a href="?id=<?= urlencode($d['id']) ?>">
                                    <span>
                                        <div class="nama"><?= h($d['nama']) ?></div>
                                        <?php if ($info): ?>
                                            <div class="info"><?= h(implode(' · ', $info)) ?></div><?php endif; ?>
                                    </span>
                                    <span class="arrow">→</span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>

        <?php else: ?>
            <a class="back" href="javascript:history.back()">← Kembali</a>

            <div class="profile">
                <h1><?= h($dosen['nama']) ?></h1>
                <div class="facts">
                    <?php if ($dosen['nama_prodi'] !== ''): ?><span><b><?= h($dosen['nama_prodi']) ?></b></span><?php endif; ?>
                    <?php if ($dosen['nidn'] !== ''): ?><span>NIDN <b><?= h($dosen['nidn']) ?></b></span><?php endif; ?>
                    <?php if ($dosen['nuptk'] !== ''): ?><span>NUPTK <b><?= h($dosen['nuptk']) ?></b></span><?php endif; ?>
                </div>
            </div>

            <section class="block">
                <h2>Riwayat pendidikan</h2>
                <?php if ($errPend): ?>
                    <div class="note"><?= h($errPend) ?></div>
                <?php elseif (!$pend): ?>
                    <p class="empty">Belum ada data riwayat pendidikan.</p>
                <?php else: ?>
                    <ol class="timeline">
                        <?php foreach ($pend as $rec):
                            $p = normalisasi_pendidikan($rec); ?>
                            <li>
                                <?php if (!array_filter($p)): ?>
                                    <dl class="kv"><?php foreach (fallback_pairs($rec) as $k => $v): ?>
                                            <dt><?= h($k) ?></dt>
                                            <dd><?= h($v) ?></dd>
                                        <?php endforeach; ?>
                                    </dl>
                                <?php else: ?>
                                    <?php if ($p['tahun'] !== ''): ?>
                                        <div class="year"><?= h($p['tahun']) ?></div><?php endif; ?>
                                    <div class="jenjang"><?= h($p['jenjang'] ?: 'Pendidikan') ?>
                                        <?php if ($p['gelar'] !== ''): ?><small>· <?= h($p['gelar']) ?></small><?php endif; ?>
                                    </div>
                                    <?php if ($p['pt'] !== ''): ?>
                                        <div class="pt"><?= h($p['pt']) ?></div><?php endif; ?>
                                    <?php if ($p['bidang'] !== ''): ?>
                                        <div class="bidang"><?= h($p['bidang']) ?></div><?php endif; ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>

            <section class="block">
                <h2>Riwayat mengajar <?php if ($meng): ?><span class="badge"><?= count($meng) ?> kelas</span><?php endif; ?>
                </h2>
                <?php if ($errMeng): ?>
                    <div class="note"><?= h($errMeng) ?></div>
                <?php elseif (!$meng): ?>
                    <p class="empty">Belum ada data riwayat mengajar.</p>
                <?php elseif (!$mengSusun['cols']): ?>
                    <p class="empty">Data mengajar diterima, tetapi tidak ada kolom yang dapat ditampilkan.</p>
                <?php else: ?>
                    <?php foreach ($mengSusun['groups'] as $sem => $baris): ?>
                        <div class="semester">
                            <h3><?= h((string) $sem) ?> <span class="badge"><?= count($baris) ?></span></h3>
                            <div class="scroll">
                                <table class="mk">
                                    <thead>
                                        <tr>
                                            <?php foreach ($mengSusun['cols'] as $c): ?>
                                                <th<?= preg_match('/sks|jml|jumlah/i', $c) ? ' class="num"' : '' ?>>
                                                    <?= h(label_kolom($c)) ?></th>
                                                <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($baris as $r): ?>
                                            <tr>
                                                <?php foreach ($mengSusun['cols'] as $c):
                                                    $v = $r[$c] ?? ''; ?>
                                                    <td<?= preg_match('/sks|jml|jumlah/i', $c) ? ' class="num"' : '' ?>>
                                                        <?= $v !== '' ? h($v) : '<span class="empty">—</span>' ?></td>
                                                    <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (isset($_GET['raw'])): ?>
                    <details class="raw" open>
                        <summary>Data mentah (3 baris pertama)</summary>
                        <pre><?= h(json_encode(array_slice($meng, 0, 3), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                    </details>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <footer>Sumber: situs PDDikti. Data dapat berbeda dengan arsip kepegawaian kampus.</footer>
    </div>
    <script>
// Peningkatan kecil: tampilan tetap berfungsi penuh tanpa JavaScript.
(function () {
  var form = document.querySelector('form.search');
  var input = form && form.querySelector('input[name="q"]');
  if (!form || !input) return;

  // Beri umpan balik saat pencarian dikirim (PDDikti kadang lambat).
  form.addEventListener('submit', function () {
    var btn = form.querySelector('button');
    if (btn) { btn.textContent = 'Mencari…'; btn.disabled = true; }
  });

  // Tekan "/" untuk langsung fokus ke kolom pencarian.
  document.addEventListener('keydown', function (e) {
    var tag = (document.activeElement || {}).tagName;
    if (e.key === '/' && tag !== 'INPUT' && tag !== 'TEXTAREA') {
      e.preventDefault();
      input.focus();
      input.select();
    }
  });

  // Kembalikan tombol bila halaman dipulihkan dari cache browser (tombol Back).
  window.addEventListener('pageshow', function () {
    var btn = form.querySelector('button');
    if (btn) { btn.textContent = 'Cari'; btn.disabled = false; }
  });
})();
</script>
</body>

</html>
