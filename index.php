<?php
/**
 * 1688 拍立得搜索 API
 * by：姜清及
 * ver：1.03
 * GET 请求参数:
 *   url: 图片链接（必填）
 *   type：结果类型，json或1688（可选，默认json）
 *   page: 页码（可选，默认1）
 *   size: 每页数量（可选，默认60，最小20，最大60）
 *   token: 完整Cookie字符串（包含_m_h5_tk等必要Cookie，可选，不提供会自动获取）
 */

header('Content-Type: application/json; charset=utf-8');

function extractTokenFromCookies($cookieString) {
    if (preg_match('/_m_h5_tk=([^;\s]+)/', $cookieString, $matches)) {
        return $matches[1];
    }
    return null;
}

function autoGetToken() {
    $url = 'https://h5api.m.1688.com/h5/mtop.relationrecommend.wirelessrecommend.recommend/2.0/';
    
    $params = [
        'jsv' => '2.7.4',
        'appKey' => '12574478'
    ];
    
    $fullUrl = $url . '?' . http_build_query($params);
    
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $fullUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => false,
        CURLOPT_HTTPHEADER => $headers
    ]);
    
    $response = curl_exec($ch);
    $cookies = [];
    
    if (preg_match_all('/Set-Cookie: (.*?);/i', $response, $matches)) {
        $cookies = $matches[1];
    }
    
    curl_close($ch);
    
    $tokenCookies = [];
    foreach ($cookies as $cookie) {
        if (strpos($cookie, '_m_h5_tk=') !== false) {
            $tokenCookies['_m_h5_tk'] = explode(';', explode('_m_h5_tk=', $cookie)[1])[0];
        } elseif (strpos($cookie, '_m_h5_tk_enc=') !== false) {
            $tokenCookies['_m_h5_tk_enc'] = explode(';', explode('_m_h5_tk_enc=', $cookie)[1])[0];
        }
    }
    
    if (!empty($tokenCookies)) {
        $cookieString = '';
        foreach ($tokenCookies as $name => $value) {
            $cookieString .= $name . '=' . $value . '; ';
        }
        return rtrim($cookieString, '; ');
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

function getSearchResults($token, $cookieString, $imageId, $page = 1, $pageSize = 60) {
    $appKey = '12574478';
    $api = 'mtop.relationrecommend.wirelessrecommend.recommend';
    
    // 限制页码和每页数量
    $page = max(1, intval($page));
    $pageSize = min(60, max(20, intval($pageSize)));
    
    $tokenParts = explode('_', $token);
    $signToken = $tokenParts[0];
    
    $paramsJson = json_encode([
        'beginPage' => $page,
        'pageSize' => $pageSize,
        'method' => 'imageOfferSearchService',
        'searchScene' => 'pcImageSearch',
        'appName' => 'pctusou',
        'tab' => 'imageSearch',
        'imageId' => $imageId
    ], JSON_UNESCAPED_UNICODE);
    
    $data = json_encode([
        'appId' => 32517,
        'params' => $paramsJson
    ], JSON_UNESCAPED_UNICODE);
    
    $timestamp = (string)(time() * 1000);
    $sign = generateSign($signToken, $timestamp, $appKey, $data);
    
    $params = [
        'jsv' => '2.7.2',
        'appKey' => $appKey,
        't' => $timestamp,
        'sign' => $sign,
        'api' => $api,
        'v' => '2.0',
        'type' => 'jsonp',
        'dataType' => 'jsonp',
        'timeout' => '20000',
        'jsonpIncPrefix' => 'reqTppId_32517_getOfferList',
        'callback' => 'mtopjsonpreqTppId_32517_getOfferList2',
        'data' => $data
    ];
    
    $url = "https://h5api.m.1688.com/h5/{$api}/2.0/";
    
    // 解析 Cookie 字符串为数组
    $cookies = [];
    $cookiePairs = explode(';', $cookieString);
    foreach ($cookiePairs as $pair) {
        $pair = trim($pair);
        if (strpos($pair, '=') !== false) {
            list($name, $value) = explode('=', $pair, 2);
            $cookies[trim($name)] = trim($value);
        }
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url . '?' . http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: */*',
            'Referer: https://s.1688.com/',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0'
        ]
    ]);
    
    // 设置 Cookie
    $cookieStr = '';
    foreach ($cookies as $name => $value) {
        $cookieStr .= $name . '=' . $value . '; ';
    }
    curl_setopt($ch, CURLOPT_COOKIE, rtrim($cookieStr, '; '));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    $jsonData = null;
    if ($response) {
        // 处理JSONP格式的响应，去除开头的空格
        $trimmedResponse = trim($response);
        if (strpos($trimmedResponse, 'mtopjsonpreqTppId_32517_getOfferList2(') === 0) {
            // 去除JSONP包装
            $jsonString = substr($trimmedResponse, strlen('mtopjsonpreqTppId_32517_getOfferList2('), -1);
            $jsonData = json_decode($jsonString, true);
        } else {
            $jsonData = json_decode($response, true);
        }
    }
    
    return [
        'success' => $httpCode == 200 && empty($error),
        'httpCode' => $httpCode,
        'error' => $error,
        'response' => $jsonData,
        'url' => $url . '?' . http_build_query($params),
        'rawResponse' => $response,
        'cookie' => $cookieString
    ];
}

if (!isset($_GET['url'])) {
    echo json_encode([
        'success' => false,
        'message' => '缺少必要参数: url'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$imageUrl = $_GET['url'];
$type = $_GET['type'] ?? 'json'; // 默认输出JSON
$page = intval($_GET['page'] ?? 1); // 默认第1页
$pageSize = min(60, max(20, intval($_GET['size'] ?? 60))); // 默认每页60条，最小20，最大60
$cookieString = $_GET['token'] ?? null;

// 如果没有提供 token，自动获取
if (!$cookieString) {
    $cookieString = autoGetToken();
    if (!$cookieString) {
        echo json_encode([
            'success' => false,
            'message' => '自动获取 token 失败，请手动提供 token'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

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
                'ret' => $uploadResult['response']['ret'],
                'rawResponse' => $uploadResult['response']
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
    
    // 根据type参数决定输出方式
    if ($type === '1688') {
        // 跳转到1688搜索页面
        $redirectUrl = "https://s.1688.com/youyuan/index.htm?tab=imageSearch&imageId={$imageId}";
        header("Location: {$redirectUrl}");
        exit;
    } else {
        // 输出JSON格式的搜索结果（调用search.php的逻辑）
        // 调用getSearchResults函数获取搜索结果
        $searchResult = getSearchResults($fullToken, $cookieString, $imageId, $page, $pageSize);
        
        if (!$searchResult['success']) {
            echo json_encode([
                'success' => false,
                'message' => '获取搜索结果失败',
                'error' => $searchResult['error'],
                'httpCode' => $searchResult['httpCode']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 检查业务是否成功
        if (isset($searchResult['response']['ret']) && is_array($searchResult['response']['ret'])) {
            $hasSuccess = false;
            foreach ($searchResult['response']['ret'] as $ret) {
                if (strpos($ret, 'SUCCESS') !== false) {
                    $hasSuccess = true;
                    break;
                }
            }
            if (!$hasSuccess) {
                echo json_encode([
                    'success' => false,
                    'message' => '业务调用失败',
                    'ret' => $searchResult['response']['ret'],
                    'rawResponse' => $searchResult['response']
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        
        // 提取商品数据
        $products = [];
        $responseData = $searchResult['response']['data'] ?? [];
        
        // 尝试不同的数据结构
        if (isset($responseData['data']['OFFER']['items']) && is_array($responseData['data']['OFFER']['items'])) {
            foreach ($responseData['data']['OFFER']['items'] as $item) {
                if (isset($item['data'])) {
                    $itemData = $item['data'];
                    // 提取几件起批信息和销量
                    $minQuantity = '';
                    $saleCount = '';
                    if (isset($itemData['afterPriceList']) && is_array($itemData['afterPriceList'])) {
                        foreach ($itemData['afterPriceList'] as $priceItem) {
                            if (isset($priceItem['matKey']) && $priceItem['matKey'] === 'quantity_begin' && isset($priceItem['text'])) {
                                $minQuantity = $priceItem['text'];
                            } elseif (isset($priceItem['matKey']) && $priceItem['matKey'] === 'sale_count' && isset($priceItem['text'])) {
                                $saleCount = $priceItem['text'];
                            }
                        }
                    }
                    
                    // 从afterPrice中提取（如果afterPriceList中没有）
                    if (empty($saleCount) && isset($itemData['afterPrice']) && is_array($itemData['afterPrice'])) {
                        if (isset($itemData['afterPrice']['matKey']) && $itemData['afterPrice']['matKey'] === 'sale_count' && isset($itemData['afterPrice']['text'])) {
                            $saleCount = $itemData['afterPrice']['text'];
                        }
                    }
                    
                    // 提取标签信息
                    $tags = [];
                    if (isset($itemData['tags']) && is_array($itemData['tags'])) {
                        foreach ($itemData['tags'] as $tag) {
                            if (isset($tag['text'])) {
                                $tags[] = $tag['text'];
                            }
                        }
                    }
                    
                    $products[] = [
                        'title' => $itemData['title'] ?? '无标题',
                        'price' => isset($itemData['priceInfo']['price']) ? '¥' . $itemData['priceInfo']['price'] : '价格未知',
                        'imageUrl' => $itemData['offerPicUrl'] ?? '',
                        'linkUrl' => $itemData['linkUrl'] ?? '',
                        'shop' => $itemData['shop']['text'] ?? '',
                        'province' => $itemData['province'] ?? '',
                        'saleQuantity' => $saleCount ?? $itemData['saleQuantity'] ?? '0',
                        'minQuantity' => $minQuantity,
                        'tags' => $tags
                    ];
                }
            }
        } elseif (isset($responseData['OFFER']['items']) && is_array($responseData['OFFER']['items'])) {
            foreach ($responseData['OFFER']['items'] as $item) {
                if (isset($item['data'])) {
                    $itemData = $item['data'];
                    // 提取几件起批信息和销量
                    $minQuantity = '';
                    $saleCount = '';
                    if (isset($itemData['afterPriceList']) && is_array($itemData['afterPriceList'])) {
                        foreach ($itemData['afterPriceList'] as $priceItem) {
                            if (isset($priceItem['matKey']) && $priceItem['matKey'] === 'quantity_begin' && isset($priceItem['text'])) {
                                $minQuantity = $priceItem['text'];
                            } elseif (isset($priceItem['matKey']) && $priceItem['matKey'] === 'sale_count' && isset($priceItem['text'])) {
                                $saleCount = $priceItem['text'];
                            }
                        }
                    }
                    
                    // 从afterPrice中提取（如果afterPriceList中没有）
                    if (empty($saleCount) && isset($itemData['afterPrice']) && is_array($itemData['afterPrice'])) {
                        if (isset($itemData['afterPrice']['matKey']) && $itemData['afterPrice']['matKey'] === 'sale_count' && isset($itemData['afterPrice']['text'])) {
                            $saleCount = $itemData['afterPrice']['text'];
                        }
                    }
                    
                    // 提取标签信息
                    $tags = [];
                    if (isset($itemData['tags']) && is_array($itemData['tags'])) {
                        foreach ($itemData['tags'] as $tag) {
                            if (isset($tag['text'])) {
                                $tags[] = $tag['text'];
                            }
                        }
                    }
                    
                    $products[] = [
                        'title' => $itemData['title'] ?? '无标题',
                        'price' => isset($itemData['priceInfo']['price']) ? '¥' . $itemData['priceInfo']['price'] : '价格未知',
                        'imageUrl' => $itemData['offerPicUrl'] ?? '',
                        'linkUrl' => isset($itemData['linkUrl']) ? str_replace('\\', '', $itemData['linkUrl']) : '',
                        'shop' => $itemData['shop']['text'] ?? '',
                        'province' => $itemData['province'] ?? '',
                        'saleQuantity' => $saleCount ?? $itemData['saleQuantity'] ?? '0',
                        'minQuantity' => $minQuantity,
                        'tags' => $tags
                    ];
                }
            }
        } elseif (isset($responseData['items']) && is_array($responseData['items'])) {
            // 尝试另一种可能的数据结构
            foreach ($responseData['items'] as $item) {
                // 提取几件起批信息和销量
                $minQuantity = '';
                $saleCount = '';
                if (isset($item['afterPriceList']) && is_array($item['afterPriceList'])) {
                    foreach ($item['afterPriceList'] as $priceItem) {
                        if (isset($priceItem['matKey']) && $priceItem['matKey'] === 'quantity_begin' && isset($priceItem['text'])) {
                            $minQuantity = $priceItem['text'];
                        } elseif (isset($priceItem['matKey']) && $priceItem['matKey'] === 'sale_count' && isset($priceItem['text'])) {
                            $saleCount = $priceItem['text'];
                        }
                    }
                }
                
                // 提取标签信息
                $tags = [];
                if (isset($item['tags']) && is_array($item['tags'])) {
                    foreach ($item['tags'] as $tag) {
                        if (isset($tag['text'])) {
                            $tags[] = $tag['text'];
                        }
                    }
                }
                
                $products[] = [
                    'title' => $item['title'] ?? '无标题',
                    'price' => isset($item['price']) ? '¥' . $item['price'] : '价格未知',
                    'imageUrl' => $item['imageUrl'] ?? '',
                    'linkUrl' => $item['linkUrl'] ?? '',
                    'shop' => $item['shop'] ?? '',
                    'province' => $item['province'] ?? '',
                    'saleQuantity' => $saleCount ?? $item['saleQuantity'] ?? '0',
                    'minQuantity' => $minQuantity,
                    'tags' => $tags
                ];
            }
        } else {
            // 尝试直接从response中获取数据
            $offerData = $responseData['data']['OFFER'] ?? $responseData['OFFER'] ?? [];
            if (isset($offerData['items']) && is_array($offerData['items'])) {
                foreach ($offerData['items'] as $item) {
                    if (isset($item['data'])) {
                        $itemData = $item['data'];
                        // 提取几件起批信息和销量
                        $minQuantity = '';
                        $saleCount = '';
                        if (isset($itemData['afterPriceList']) && is_array($itemData['afterPriceList'])) {
                            foreach ($itemData['afterPriceList'] as $priceItem) {
                                if (isset($priceItem['matKey']) && $priceItem['matKey'] === 'quantity_begin' && isset($priceItem['text'])) {
                                    $minQuantity = $priceItem['text'];
                                } elseif (isset($priceItem['matKey']) && $priceItem['matKey'] === 'sale_count' && isset($priceItem['text'])) {
                                    $saleCount = $priceItem['text'];
                                }
                            }
                        }
                        
                        // 从afterPrice中提取（如果afterPriceList中没有）
                        if (empty($saleCount) && isset($itemData['afterPrice']) && is_array($itemData['afterPrice'])) {
                            if (isset($itemData['afterPrice']['matKey']) && $itemData['afterPrice']['matKey'] === 'sale_count' && isset($itemData['afterPrice']['text'])) {
                                $saleCount = $itemData['afterPrice']['text'];
                            }
                        }
                        $products[] = [
                            'title' => $itemData['title'] ?? '无标题',
                            'price' => isset($itemData['priceInfo']['price']) ? '¥' . $itemData['priceInfo']['price'] : '价格未知',
                            'imageUrl' => $itemData['offerPicUrl'] ?? '',
                            'linkUrl' => $itemData['linkUrl'] ?? '',
                            'shop' => $itemData['shop']['text'] ?? '',
                            'province' => $itemData['province'] ?? '',
                            'saleQuantity' => $saleCount ?? $itemData['saleQuantity'] ?? '0',
                            'minQuantity' => $minQuantity
                        ];
                    }
                }
            }
        }
        
        // 检查是否是 mock 数据
        $isMock = isset($responseData['mock']) && in_array('mock', $responseData['mock']);
        
        // 即使是 mock 数据，如果有商品数据，也视为有效
        $hasValidData = !empty($products);
        
        // 获取API返回的总数量（优先使用found字段，这是实际找到的商品数量）
        $totalFound = 0;
        if (isset($responseData['data']['OFFER']['found'])) {
            $totalFound = intval($responseData['data']['OFFER']['found']);
        } elseif (isset($responseData['OFFER']['found'])) {
            $totalFound = intval($responseData['OFFER']['found']);
        } elseif (isset($responseData['data']['OFFER']['totalCount'])) {
            $totalFound = intval($responseData['data']['OFFER']['totalCount']);
        } elseif (isset($responseData['OFFER']['totalCount'])) {
            $totalFound = intval($responseData['OFFER']['totalCount']);
        } elseif (isset($responseData['found'])) {
            $totalFound = intval($responseData['found']);
        } elseif (isset($responseData['totalCount'])) {
            $totalFound = intval($responseData['totalCount']);
        }
        
        $currentCount = count($products);
        
        // 计算总页数
        $totalPages = $totalFound > 0 ? ceil($totalFound / $pageSize) : 1;
        
        // 输出搜索结果
        echo json_encode([
            'success' => true,
            'imageId' => $imageId,
            'products' => $products,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $totalFound > 0 ? $totalFound : $currentCount,
                'current' => $currentCount,
                'totalPages' => $totalPages,
                'hasMore' => $page < $totalPages
            ],
            'display' => $totalFound > 0 ? "找到 {$totalFound} 个商品，当前显示第 {$page} 页 {$currentCount} 个" : "找到 {$currentCount} 个商品",
            'isMock' => $isMock,
            'message' => $hasValidData ? '获取成功' : ($isMock ? '返回的是模拟数据，可能需要登录态或图片ID已过期' : '获取成功')
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '发生错误: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
