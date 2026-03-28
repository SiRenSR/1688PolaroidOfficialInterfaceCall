<?php
/**
 * 1688 图片搜索 API - 简洁版
 * GET 请求参数:
 *   url: 图片链接
 *   token: 完整Cookie字符串（包含_m_h5_tk等必要Cookie）
 */

header('Content-Type: application/json; charset=utf-8');

function extractTokenFromCookies($cookieString) {
    if (preg_match('/_m_h5_tk=([^;\s]+)/', $cookieString, $matches)) {
        return $matches[1];
    }
    return null;
}

function getTokenRemainingMinutes($fullToken) {
    $tokenParts = explode('_', $fullToken);
    if (count($tokenParts) >= 2) {
        $tokenTimestamp = (int)$tokenParts[1];
        $currentTimestamp = time() * 1000;
        $diffMs = $tokenTimestamp - $currentTimestamp;
        $remainingMinutes = floor($diffMs / 60000);
        return $remainingMinutes;
    }
    return null;
}

function extractEssentialCookies($cookieString) {
    $essential = ['_m_h5_tk', '_m_h5_tk_enc', 'cna', 'isg', 'cookie2', 't', '_tb_token_'];
    $cookies = [];
    foreach ($essential as $name) {
        if (preg_match('/' . preg_quote($name, '/') . '=([^;\s]+)/', $cookieString, $matches)) {
            $cookies[$name] = $matches[1];
        }
    }
    $result = '';
    foreach ($cookies as $name => $value) {
        if ($result !== '') $result .= '; ';
        $result .= $name . '=' . $value;
    }
    return $result;
}

function generateSign($token, $timestamp, $appKey, $data) {
    $signString = $token . '&' . $timestamp . '&' . $appKey . '&' . $data;
    return md5($signString);
}

function downloadAndCompressImage($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    
    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode != 200 || !$imageData) {
        return ['success' => false, 'error' => '图片下载失败: ' . ($error ?: "HTTP $httpCode")];
    }
    
    $tempFile = tempnam(sys_get_temp_dir(), 'img_');
    file_put_contents($tempFile, $imageData);
    
    $info = getimagesize($tempFile);
    if (!$info) {
        unlink($tempFile);
        return ['success' => false, 'error' => '不是有效的图片'];
    }
    
    $width = $info[0];
    $height = $info[1];
    $mime = $info['mime'];
    
    $maxWidth = 800;
    $maxHeight = 800;
    $quality = 70;
    
    if ($width > $maxWidth || $height > $maxHeight) {
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = (int)($width * $ratio);
        $newHeight = (int)($height * $ratio);
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }
    
    switch ($mime) {
        case 'image/jpeg': $srcImage = imagecreatefromjpeg($tempFile); break;
        case 'image/png': $srcImage = imagecreatefrompng($tempFile); break;
        case 'image/gif': $srcImage = imagecreatefromgif($tempFile); break;
        case 'image/webp': $srcImage = imagecreatefromwebp($tempFile); break;
        default: unlink($tempFile); return ['success' => false, 'error' => '不支持的图片格式'];
    }
    
    if (!$srcImage) {
        unlink($tempFile);
        return ['success' => false, 'error' => '图片解码失败'];
    }
    
    $dstImage = imagecreatetruecolor($newWidth, $newHeight);
    
    if ($mime == 'image/png' || $mime == 'image/gif') {
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);
        $transparent = imagecolorallocatealpha($dstImage, 255, 255, 255, 127);
        imagefilledrectangle($dstImage, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    ob_start();
    imagejpeg($dstImage, null, $quality);
    $compressedData = ob_get_clean();
    
    imagedestroy($srcImage);
    imagedestroy($dstImage);
    unlink($tempFile);
    
    return ['success' => true, 'data' => base64_encode($compressedData)];
}

function call1688Api($token, $cookieString, $imageBase64) {
    $appKey = '12574478';
    $api = 'mtop.relationrecommend.wirelessrecommend.recommend';
    
    $tokenParts = explode('_', $token);
    $signToken = substr($tokenParts[0], 0, 32);
    
    $paramsJson = json_encode([
        'beginPage' => 1,
        'pageSize' => 60,
        'searchScene' => 'pcImageSearch',
        'method' => 'uploadBase64WithRequest',
        'appName' => 'pctusou',
        'imageBase64' => $imageBase64
    ]);
    
    $data = json_encode([
        'appId' => 32517,
        'params' => $paramsJson
    ]);
    
    $timestamp = (string)(time() * 1000);
    $sign = generateSign($signToken, $timestamp, $appKey, $data);
    
    $params = [
        'jsv' => '2.7.2',
        'appKey' => $appKey,
        't' => $timestamp,
        'sign' => $sign,
        'api' => $api,
        'v' => '2.0',
        'type' => 'originaljson',
        'timeout' => '20000',
        'jsonpIncPrefix' => 'reqTppId_32517_getOfferList',
        'dataType' => 'jsonp'
    ];
    
    $url = "https://h5api.m.1688.com/h5/{$api}/2.0/?" . http_build_query($params);
    $essentialCookieString = extractEssentialCookies($cookieString);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['data' => $data]),
        CURLOPT_COOKIE => $essentialCookieString,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'Referer: https://www.1688.com/',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    $jsonData = null;
    if ($response) {
        $jsonData = json_decode($response, true);
        if (!$jsonData && preg_match('/\((.*)\)/s', $response, $matches)) {
            $jsonData = json_decode($matches[1], true);
        }
    }
    
    return [
        'success' => $httpCode == 200 && empty($error),
        'httpCode' => $httpCode,
        'error' => $error,
        'response' => $jsonData
    ];
}

function getSearchResults($token, $cookieString, $imageId) {
    $appKey = '12574478';
    $api = 'mtop.relationrecommend.wirelessrecommend.recommend';
    
    $tokenParts = explode('_', $token);
    $signToken = substr($tokenParts[0], 0, 32);
    
    $paramsJson = json_encode([
        'beginPage' => 1,
        'pageSize' => 60,
        'searchScene' => 'pcImageSearch',
        'method' => 'getOfferListByImageId',
        'appName' => 'pctusou',
        'imageId' => $imageId
    ]);
    
    $data = json_encode([
        'appId' => 32517,
        'params' => $paramsJson
    ]);
    
    $timestamp = (string)(time() * 1000);
    $sign = generateSign($signToken, $timestamp, $appKey, $data);
    
    $params = [
        'jsv' => '2.7.2',
        'appKey' => $appKey,
        't' => $timestamp,
        'sign' => $sign,
        'api' => $api,
        'v' => '2.0',
        'type' => 'originaljson',
        'timeout' => '20000',
        'jsonpIncPrefix' => 'reqTppId_32517_getOfferList',
        'dataType' => 'jsonp'
    ];
    
    $url = "https://h5api.m.1688.com/h5/{$api}/2.0/?" . http_build_query($params);
    $essentialCookieString = extractEssentialCookies($cookieString);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['data' => $data]),
        CURLOPT_COOKIE => $essentialCookieString,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'Referer: https://www.1688.com/',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    $jsonData = null;
    if ($response) {
        $jsonData = json_decode($response, true);
        if (!$jsonData && preg_match('/\((.*)\)/s', $response, $matches)) {
            $jsonData = json_decode($matches[1], true);
        }
    }
    
    return [
        'success' => $httpCode == 200 && empty($error),
        'httpCode' => $httpCode,
        'error' => $error,
        'response' => $jsonData
    ];
}

if (!isset($_GET['url']) || !isset($_GET['token'])) {
    echo json_encode([
        'success' => false,
        'message' => '缺少必要参数: url 和 token'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$imageUrl = $_GET['url'];
$cookieString = $_GET['token'];

try {
    $fullToken = extractTokenFromCookies($cookieString);
    if (!$fullToken) {
        echo json_encode([
            'success' => false,
            'message' => '无法从token中提取_m_h5_tk'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $tokenRemainingMinutes = getTokenRemainingMinutes($fullToken);
    
    $downloadResult = downloadAndCompressImage($imageUrl);
    if (!$downloadResult['success']) {
        echo json_encode([
            'success' => false,
            'message' => $downloadResult['error']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $uploadResult = call1688Api($fullToken, $cookieString, $downloadResult['data']);
    
    if (!$uploadResult['success']) {
        echo json_encode([
            'success' => false,
            'message' => 'API调用失败',
            'error' => $uploadResult['error'],
            'httpCode' => $uploadResult['httpCode']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (isset($uploadResult['response']['ret']) && is_array($uploadResult['response']['ret'])) {
        $hasSuccess = false;
        foreach ($uploadResult['response']['ret'] as $ret) {
            if (strpos($ret, 'SUCCESS') !== false) {
                $hasSuccess = true;
                break;
            }
        }
        if (!$hasSuccess) {
            echo json_encode([
                'success' => false,
                'message' => '业务调用失败',
                'ret' => $uploadResult['response']['ret']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    $imageId = $uploadResult['response']['data']['data']['imageId'] ?? null;
    if (!$imageId) {
        echo json_encode([
            'success' => false,
            'message' => '未获取到imageId',
            'rawResponse' => $uploadResult['response']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $result = [
        'success' => true,
        'imageId' => $imageId
    ];
    
    if ($tokenRemainingMinutes !== null) {
        $result['tokenRemainingMinutes'] = $tokenRemainingMinutes;
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '发生错误: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
