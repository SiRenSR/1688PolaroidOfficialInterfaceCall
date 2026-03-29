# 1688 图片搜索 API

一个简洁的 PHP 接口，用于调用 1688 图片搜索功能。

## 文件说明

- `index.php` - API 接口文件（支持自动获取 token，返回搜索结果）
- `test.html` - Cookie 转换工具页面（备用，用于手动提供 token）

## 环境要求

- PHP 7.0+
- GD 库（用于图片处理）
- cURL 扩展

## 使用方法

### 方法 1（推荐）：自动获取 token

直接调用 API 即可，无需手动获取 Cookie：
```
index.php?url=https://example.com/image.jpg
```

### 方法 2（手动提供 token）：

当自动获取 token 失败时，可以手动提供：

1. **获取 Cookie**：从 1688 网站获取完整的 Cookie 字符串
2. **转换参数**：打开 `test.html`，粘贴 Cookie，自动生成 URL 编码后的 token 参数
3. **调用 API**：

使用 GET 方式请求 `index.php`，传递以下参数：

| 参数 | 说明 | 必需 |
|------|------|------|
| url | 图片链接 | 是 |
| type | 结果类型，json 或 1688（默认 json） | 否 |
| page | 页码（默认 1） | 否 |
| size | 每页数量（默认 60，最小 20，最大 60） | 否 |
| token | URL 编码后的完整 Cookie 字符串（可选，不提供会自动获取） | 否 |

示例 1（自动获取 token，默认第 1 页）：
```
index.php?url=https://example.com/image.jpg
```

示例 2（手动提供 token，获取第 2 页，每页 30 条）：
```
index.php?url=https://example.com/image.jpg&token=编码后的Cookie&page=2&size=30
```

示例 3（跳转到 1688 搜索页面）：
```
index.php?url=https://example.com/image.jpg&type=1688
```

## API 返回格式

### 成功响应（json 类型）
```json
{
  "success": true,
  "imageId": "1610008712442022303",
  "products": [
    {
      "title": "商品标题",
      "price": "¥10.00",
      "imageUrl": "https://example.com/image.jpg",
      "linkUrl": "https://detail.1688.com/offer/123456.html",
      "shop": "店铺名称",
      "province": "省份",
      "saleQuantity": "1.2万+件",
      "minQuantity": "2件起批",
      "tags": ["标签1", "标签2"]
    }
  ],
  "pagination": {
    "page": 1,
    "pageSize": 60,
    "total": 697,
    "current": 60,
    "totalPages": 12,
    "hasMore": true
  },
  "display": "找到 697 个商品，当前显示第 1 页 60 个",
  "isMock": false,
  "message": "获取成功"
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
3. 使用 `imageId` 调用搜索 API 获取商品列表
4. 解析搜索结果，提取商品信息
5. 返回 JSON 格式的搜索结果或跳转到 1688 搜索页面

## 注意事项

- Token（Cookie）有效期约 60 分钟
- 图片会自动压缩以适应 API 限制
- 支持的图片格式：JPEG、PNG、GIF、WebP
- `size` 参数限制：最小 20，最大 60
- 当 `type=1688` 时，会直接跳转到 1688 搜索页面
- 自动获取 token 可能会失败，此时需要手动提供
