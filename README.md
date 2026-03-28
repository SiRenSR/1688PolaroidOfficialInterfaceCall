# 1688 图片搜索 API

一个简洁的 PHP 接口，用于调用 1688 图片搜索功能。

## 文件说明

- `index.php` - API 接口文件
- `test.html` - Cookie 转换工具页面

## 环境要求

- PHP 7.0+
- GD 库（用于图片处理）
- cURL 扩展

## 使用方法

### 1. 获取 Cookie

从 1688 网站获取完整的 Cookie 字符串，确保包含以下必要的 Cookie：
- `_m_h5_tk`
- `_m_h5_tk_enc`
- `cna`
- `isg`
- `cookie2`
- `t`
- `_tb_token_`

### 2. 转换 Cookie 为 URL 参数

打开 `test.html`，粘贴 Cookie，自动生成 URL 编码后的 token 参数。

### 3. 调用 API

使用 GET 方式请求 `index.php`，传递以下参数：

| 参数 | 说明 | 必需 |
|------|------|------|
| url | 图片链接 | 是 |
| token | URL 编码后的完整 Cookie 字符串 | 是 |

示例：
```
index.php?url=https://example.com/image.jpg&token=编码后的Cookie
```

## API 返回格式

### 成功响应
```json
{
  "success": true,
  "imageId": "1610008712442022303",
  "tokenRemainingMinutes": 72
}
```

### 失败响应
```json
{
  "success": false,
  "message": "错误描述"
}
```

## 工作原理

1. 下载并压缩图片（最大 800x800，质量 70%）
2. 调用 1688 API 上传图片获取 `imageId`
3. 使用 `imageId` 调用搜索接口获取商品列表
4. 返回格式化的搜索结果

## 注意事项

- Token（Cookie）有效期约 60 分钟
- 图片会自动压缩以适应 API 限制
- 支持的图片格式：JPEG、PNG、GIF、WebP
