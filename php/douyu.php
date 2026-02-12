<?php
header('Content-Type: text/plain; charset=utf-8');

class DouyuStream {
    private $roomId;
    private $did;
    private $cookies = [];
    
    public function __construct($roomId) {
        $this->roomId = $roomId;
        $this->getCookies();
        $this->extractDid();
    }
    
    private function getCookies() {
        $ch = curl_init("https://www.douyu.com/" . $this->roomId);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 5,
        ]);
        
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        curl_close($ch);
        
        $lines = explode("\n", $headers);
        foreach ($lines as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookieLine = substr($line, 12);
                $cookieParts = explode(';', $cookieLine);
                if (count($cookieParts) > 0) {
                    $cookiePair = explode('=', trim($cookieParts[0]), 2);
                    if (count($cookiePair) == 2) {
                        $this->cookies[trim($cookiePair[0])] = trim($cookiePair[1]);
                    }
                }
            }
        }
    }
    
    private function extractDid() {
        if (isset($this->cookies['dy_did'])) {
            $this->did = $this->cookies['dy_did'];
        } else {
            $this->did = substr(md5(microtime() . mt_rand()), 0, 32);
            $this->cookies['dy_did'] = $this->did;
        }
        
        if (!isset($this->cookies['mantine-color-scheme-value'])) {
            $this->cookies['mantine-color-scheme-value'] = 'light';
        }
    }
    
    private function getCookiesString() {
        $result = [];
        foreach ($this->cookies as $name => $value) {
            $result[] = $name . '=' . $value;
        }
        return implode('; ', $result);
    }
    
    private function getEncryptionKey() {
        $url = "https://www.douyu.com/wgapi/livenc/liveweb/websec/getEncryption?did=" . $this->did;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'authority: www.douyu.com',
                'referer: https://www.douyu.com/' . $this->roomId,
                'origin: https://www.douyu.com',
                'content-type: application/x-www-form-urlencoded',
                'x-requested-with: XMLHttpRequest',
            ],
            CURLOPT_COOKIE => $this->getCookiesString(),
            CURLOPT_TIMEOUT => 5,
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        return ($data && $data['error'] == 0) ? $data['data'] : false;
    }
    
    private function calculateAuth($keyData, $timestamp) {
        $key = $keyData['key'];
        $randStr = $keyData['rand_str'];
        $encTime = $keyData['enc_time'];
        
        $u = $randStr;
        for ($i = 0; $i < $encTime; $i++) {
            $u = md5($u . $key);
        }
        
        return md5($u . $key . $this->roomId . $timestamp);
    }
    
    private function updateDidFromStream($streamData) {
        if (isset($streamData['rtmp_live']) && preg_match('/did=([a-f0-9]{32})/', $streamData['rtmp_live'], $matches)) {
            $newDid = $matches[1];
            if ($newDid !== $this->did) {
                $this->did = $newDid;
                $this->cookies['dy_did'] = $this->did;
                return true;
            }
        }
        return false;
    }
    
    public function getStreamUrl() {
        $keyData = $this->getEncryptionKey();
        if (!$keyData) return false;
        
        $timestamp = time();
        $auth = $this->calculateAuth($keyData, $timestamp);
        
        $postData = [
            'enc_data' => $keyData['enc_data'],
            'tt' => $timestamp,
            'did' => $this->did,
            'auth' => $auth,
            'cdn' => '',
            'rate' => '',
            'hevc' => '0',
            'fa' => '0',
            'ive' => '0',
        ];
        
        $url = "https://www.douyu.com/lapi/live/getH5PlayV1/" . $this->roomId;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'authority: www.douyu.com',
                'referer: https://www.douyu.com/' . $this->roomId,
                'origin: https://www.douyu.com',
                'content-type: application/x-www-form-urlencoded',
                'x-requested-with: XMLHttpRequest',
            ],
            CURLOPT_COOKIE => $this->getCookiesString(),
            CURLOPT_TIMEOUT => 5,
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        if (!$data || $data['error'] != 0) return false;
        
        $streamData = $data['data'];
        
        if ($this->updateDidFromStream($streamData)) {
            $keyData = $this->getEncryptionKey();
            if ($keyData) {
                $auth = $this->calculateAuth($keyData, $timestamp);
                $postData['did'] = $this->did;
                $postData['auth'] = $auth;
                
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query($postData),
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_HTTPHEADER => [
                        'authority: www.douyu.com',
                        'referer: https://www.douyu.com/' . $this->roomId,
                        'origin: https://www.douyu.com',
                        'content-type: application/x-www-form-urlencoded',
                        'x-requested-with: XMLHttpRequest',
                    ],
                    CURLOPT_COOKIE => $this->getCookiesString(),
                    CURLOPT_TIMEOUT => 5,
                ]);
                
                $response = curl_exec($ch);
                curl_close($ch);
                
                $data = json_decode($response, true);
                if (!$data || $data['error'] != 0) return false;
                $streamData = $data['data'];
            }
        }
        
        if (isset($streamData['rtmp_url'], $streamData['rtmp_live'])) {
            return $streamData['rtmp_url'] . '/' . $streamData['rtmp_live'];
        }
        if (isset($streamData['hls_url']) && $streamData['hls_url']) {
            return $streamData['hls_url'];
        }
        
        return false;
    }
}

// 处理获取单个直播流
if (isset($_GET['id']) && !empty($_GET['id'])) {
    set_time_limit(10);
    $douyu = new DouyuStream(intval($_GET['id']));
    $streamUrl = $douyu->getStreamUrl();
    
    if ($streamUrl) {
        header("Location: " . $streamUrl);
        exit();
    } else {
        echo "获取直播流失败\n";
    }
} else {
    // 生成固定分类(2_208颜值区)的直播列表
    $baseUrl = "https://www.douyu.com/gapi/rknc/directory/mixListV1/2_208/";
    $cacheDir = __DIR__ . '/cache';
    $cacheFile = "$cacheDir/douyu_list.m3u";
    $cacheTime = 30 * 60; // 缓存时间 30 分钟
    
    // 确保缓存目录存在
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0777, true);
    }
    
    // 获取当前时间
    $currentTime = date('Y-m-d H:i:s');
    
    // 插入一个特殊的频道（包含当前时间）
    $playlist = [
        "#EXTM3U",
        "#EXTINF:-1 tvg-name=\"$currentTime\" tvg-logo=\"https://shark2.douyucdn.cn/front-publish/douyu-web-master/_next/static/media/logo.866d9f02.png\" group-title=\"更新时间\",$currentTime",
        "https://cdn.jsdelivr.net/gh/feiyangdigital/testvideo/time/time.mp4"
    ];
    
    // 检查缓存是否有效
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
        readfile($cacheFile);
        exit;
    }
    
    // 通用函数：发送请求
    function sendRequest($url, $postData = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if ($postData) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
    
    $groupedData = [];
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $basePath = "http://$host$script";
    
    // 循环请求 API 数据
    $page = 1;
    $maxPages = 3; // 限制最多获取3页，避免请求过多
    
    while ($page <= $maxPages) {
        $url = $baseUrl . $page;
        $response = sendRequest($url);
        $data = json_decode($response, true);
        
        if (!$data || $data['code'] !== 0) {
            break; // 如果数据无效，停止请求
        }
        
        // 处理数据
        if (!empty($data['data']['rl'])) {
            foreach ($data['data']['rl'] as $item) {
                $rid = $item['rid'];
                $rn = htmlspecialchars($item['rn'], ENT_QUOTES, 'UTF-8');
                $nn = htmlspecialchars($item['nn'], ENT_QUOTES, 'UTF-8');
                $c2name = htmlspecialchars($item['c2name'], ENT_QUOTES, 'UTF-8');
                $logo = isset($item['av']) ? $item['av'] : '';
                
                if (!isset($groupedData[$c2name])) {
                    $groupedData[$c2name] = [];
                }
                $groupedData[$c2name][] = [
                    'rid' => $rid,
                    'rn' => $rn,
                    'nn' => $nn,
                    'logo' => $logo,
                    'c2name' => $c2name
                ];
            }
        }
        
        // 检查当前页是否返回空数组，表示没有更多数据
        if (empty($data['data']['rl'])) {
            break; // 如果当前页数据为空，退出循环
        }
        
        $page++; // 否则继续请求下一页
    }
    
    // 按分类名排序
    ksort($groupedData);
    
    // 生成 M3U 播放列表
    foreach ($groupedData as $c2name => $items) {
        foreach ($items as $item) {
            $rid = $item['rid'];
            $rn = $item['rn'];
            $nn = $item['nn']; // 主播昵称
            $logo = $item['logo'];
            $playlist[] = "#EXTINF:-1 tvg-logo=\"$logo\" group-title=\"$c2name\",$rn @$nn";
            $playlist[] = "$basePath?id=$rid";
        }
    }
    
    // 保存到缓存文件
    file_put_contents($cacheFile, implode("\n", $playlist));
    
    // 输出播放列表
    echo implode("\n", $playlist);
}
?>