# Pterodactyl Client API: tài liệu API (v2.1.0)

> Package: `nextscale-asia/pterodactyl-client-api` (namespace `Byzic\PterodactylClientApi`)
> Panel hỗ trợ: 1.13, 1.14 (Laravel 11), 1.15 (Laravel 12); PHP 8.2+
> Cập nhật: 2026-10-04

Addon thêm 4 endpoint vào Application API của Pterodactyl Panel. Panel gốc không có các endpoint này:

| # | Method | Path | Mục đích |
|---|--------|------|----------|
| 1 | `GET` | `/api/application/users/{user}/api-keys` | Liệt kê account API key (`ptlc_`) của một user |
| 2 | `POST` | `/api/application/users/{user}/api-keys` | Tạo account API key cho user |
| 3 | `DELETE` | `/api/application/users/{user}/api-keys/{identifier}` | Xoá một account API key của user |
| 4 | `GET` | `/api/application/nodes/{node}/allocations/free` | Liệt kê allocation chưa gán server của một node |

Panel gốc chỉ cho user tự quản lý key **của chính mình** (`/api/client/account/api-keys`). Addon cho phép một hệ thống bên ngoài (billing, provisioning) dùng application key của admin để tạo client key cho từng user, rồi dùng client key đó điều khiển server thay user (console, power, file).

Mọi endpoint đều dùng lại các hàm có sẵn của panel: `User::createToken()`, `Client\ApiKeyTransformer`, `Activity` facade và nhóm middleware `application-api`. Vì vậy key tạo qua addon giống hệt key user tự tạo trong trang tài khoản.

---

## 1. Cài đặt

Chạy trong thư mục panel (ví dụ `/var/www/pterodactyl`):

```bash
composer require nextscale-asia/pterodactyl-client-api:^2.1
php artisan optimize:clear
php artisan route:list --path=api-keys   # phải thấy 3 route api-keys
```

- Panel 1.13 / 1.14 (Laravel 11): chạy `composer require` như trên, **không** thêm `-W` / `--with-all-dependencies`. Composer hiện chặn mọi bản Laravel 11 vì security advisory, nên resolve lại `laravel/framework` sẽ lỗi; `require` thường giữ nguyên bản Laravel panel đã khoá.
- Service provider tự đăng ký qua `extra.laravel.providers`. `boot()` nạp `routes/api.php`, `register()` merge config vào key `pterodactyl-client-api`.
- ⚠️ **Nâng cấp panel sẽ gỡ mất addon.** Quy trình nâng cấp chuẩn của Pterodactyl giải nén bản mới đè lên thư mục panel (ghi đè `composer.json`) rồi chạy `composer install`. Sau mỗi lần nâng cấp panel phải chạy lại `composer require`, hoặc đưa bước này vào image/script nâng cấp.

## 2. Xác thực và phân quyền

### 2.1 Middleware

Routes khai báo trong [routes/api.php](../routes/api.php), dùng đúng stack của `/api/application` gốc (`RouteServiceProvider` của panel):

```php
Route::middleware(['api', RequireTwoFactorAuthentication::class, 'application-api', 'throttle:api.application'])
    ->prefix('/api/application')
```

| Lớp | Tác dụng |
|-----|----------|
| `api` | Sanctum xác thực Bearer token hoặc session, kiểm JSON, cập nhật `last_used_at`, kiểm `allowed_ips` của key |
| `RequireTwoFactorAuthentication` | Áp dụng chính sách 2FA của panel |
| `application-api` | `SubstituteBindings` (`{user:id}`, `{node:id}` → model, không có thì 404) và `AuthenticateApplicationUser` (bắt buộc `root_admin`) |
| `throttle:api.application` | Rate limit giống Application API gốc |
| FormRequest `authorize()` | Kiểm ACL của application key; từ chối user đích là root admin (xem 2.3) |

### 2.2 Credential được chấp nhận

| Credential | Kết quả |
|------------|---------|
| Application key `ptla_…` của root admin | Được phép, giới hạn theo ACL của key (bảng 2.4) |
| Application key của admin đã bị hạ quyền | 403 (`AuthenticateApplicationUser`) |
| Account key `ptlc_…` của user thường | 403 |
| Session cookie của user thường | 403 |
| Account key / session của root admin | Được phép, bỏ qua ACL (hành vi gốc của `ApplicationApiRequest` trong panel) |

### 2.3 Không thao tác trên root admin

Cả 3 endpoint api-keys trả **403** khi `{user}` là root admin. Lý do: một account key của root admin có toàn quyền panel, và việc tạo key không để lại dấu hiệu nào với admin đó (khác với đổi mật khẩu). Bật lại bằng `CLIENT_API_ALLOW_ADMIN_TARGETS=true` nếu thật sự cần.

### 2.4 ACL cần cho application key

Trong Admin → Application API, chọn quyền cho key:

| Endpoint | Resource | Mức tối thiểu | Ghi chú |
|----------|----------|---------------|---------|
| GET api-keys | Users | Read | |
| POST api-keys | Users | Write | |
| DELETE api-keys | Users | Write | Panel không có mức "Delete"; `DeleteUserRequest` gốc cũng dùng Write |
| GET allocations/free | Allocations | Read | |

`AdminAcl` kiểm theo bitmask (`r_users & mức yêu cầu`). Trong giao diện panel, "Read & Write" = 3, đủ cho cả ba endpoint api-keys. Nếu key chỉ có Write (2), GET sẽ bị 403.

### 2.5 Header

```
Authorization: Bearer ptla_xxxxxxxxxxx<token 32 ký tự>
Accept: application/json
Content-Type: application/json
```

Body phải là JSON hợp lệ (`IsValidJson` trả 400 nếu không parse được).

### 2.6 Audit log

Tạo và xoá key được ghi vào activity log của panel:

| Sự kiện | Actor | Subject | Property |
|---------|-------|---------|----------|
| `user:api-key.create` | Admin sở hữu application key | User đích, key mới | `identifier` |
| `user:api-key.delete` | Admin sở hữu application key | User đích | `identifier` |

Vì user đích là subject, sự kiện hiện trên trang **Account → Activity** của chính user đó ("Created new API key ptlc_…"). Liệt kê key (GET) không ghi log, giống panel gốc.

---

## 3. GET `/api/application/users/{user}/api-keys`

Liệt kê toàn bộ account API key của user (`$user->apiKeys`, đã lọc `key_type = 1`).

| Param | Kiểu | Ghi chú |
|-------|------|---------|
| `user` | int (path) | ID user panel, không phải `external_id` |

Không phân trang: mỗi user chỉ có vài key (panel giới hạn 25, addon mặc định 5).

**Response 200**

```json
{
  "object": "list",
  "data": [
    {
      "object": "api_key",
      "attributes": {
        "identifier": "ptlc_AbCdEf12345",
        "description": "byzic-provision",
        "allowed_ips": [],
        "last_used_at": null,
        "created_at": "2026-10-04T08:00:00+00:00"
      }
    }
  ]
}
```

Token đã lưu không bao giờ xuất hiện trong response (`ApiKey::$hidden = ['token']`).

| Mã | Khi nào |
|----|---------|
| 401 | Thiếu hoặc sai credential |
| 403 | Không phải admin / ACL thiếu Users Read / user đích là root admin / IP ngoài `allowed_ips` của credential |
| 404 | User không tồn tại hoặc `{user}` không phải số |
| 429 | Vượt rate limit |

---

## 4. POST `/api/application/users/{user}/api-keys`

Tạo account API key cho user. Secret chỉ trả về **một lần**.

**Body** (giống hệt `StoreApiKeyRequest` của panel):

| Field | Rule | Ghi chú |
|-------|------|---------|
| `description` | `required|nullable|string|max:500` | Lưu vào cột `memo`. **Không** bắt buộc khác nhau giữa các key |
| `allowed_ips` | `nullable|array|max:50` | Rỗng hoặc bỏ trống = không giới hạn IP |
| `allowed_ips.*` | `string`, phải là IP hoặc CIDR hợp lệ (`Range::parse`) | Ví dụ `203.0.113.10`, `10.0.0.0/24`, `2001:db8::/32` |

```bash
curl -X POST "https://panel.example.com/api/application/users/42/api-keys" \
  -H "Authorization: Bearer ptla_..." \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"description":"byzic-provision","allowed_ips":[]}'
```

**Xử lý** ([ApiKeyController::store](../src/Http/Controllers/ApiKeyController.php))
1. Trong `DB::transaction`: khoá và đếm key account của user (`lockForUpdate`). Nếu `>= max_keys_per_user` thì ném `DisplayException` (400). Khoá dòng chặn trường hợp nhiều request song song cùng vượt giới hạn.
2. `$user->createToken($description, $allowed_ips)`: identifier `ptlc_` + 11 ký tự, token 32 ký tự lưu dạng `encrypt()`.
3. Ghi `user:api-key.create`.
4. Trả key kèm `meta.secret_token`.

**Response 200**

```json
{
  "object": "api_key",
  "attributes": {
    "identifier": "ptlc_AbCdEf12345",
    "description": "byzic-provision",
    "allowed_ips": [],
    "last_used_at": null,
    "created_at": "2026-10-04T08:00:00+00:00"
  },
  "meta": {
    "secret_token": "<32 ký tự>"
  }
}
```

**Key đầy đủ = `attributes.identifier` + `meta.secret_token`** (16 + 32 = 48 ký tự), dùng làm Bearer token cho Client API. Đây đúng là hợp đồng của `POST /api/client/account/api-keys` gốc, và cũng là cách hosting-api đang ghép (`account.service.ts`, `createClientKey`).

| Mã | Khi nào |
|----|---------|
| 400 | User đã có đủ `max_keys_per_user` key. Tính **mọi** account key của user, kể cả key user tự tạo trong panel |
| 403 | Không phải admin / ACL thiếu Users Write / user đích là root admin |
| 404 | User không tồn tại |
| 422 | `description` thiếu hoặc quá 500 ký tự; `allowed_ips` quá 50 phần tử hoặc có giá trị không phải IP/CIDR |

Ví dụ 422 (định dạng lỗi chuẩn của panel):

```json
{
  "errors": [
    {
      "code": "ValidationException",
      "status": "422",
      "detail": "\"abc\" is not a valid IP address or CIDR range.",
      "meta": { "source_field": "allowed_ips.0", "rule": "..." }
    }
  ]
}
```

---

## 5. DELETE `/api/application/users/{user}/api-keys/{identifier}`

Xoá một account API key của user.

| Param | Ràng buộc | Ghi chú |
|-------|-----------|---------|
| `user` | `{user:id}` binding | Không tồn tại → 404 |
| `identifier` | regex `[A-Za-z0-9_]{16}` trên route | Sai định dạng → 404 (route không khớp), không phải 422 |

**Xử lý**: tìm key theo `identifier` trong `$user->apiKeys()` (không có → 404); ghi `user:api-key.delete`; xoá; trả **204** không có body. Key của user khác không bao giờ bị xoá qua route của user này.

| Mã | Khi nào |
|----|---------|
| 204 | Đã xoá |
| 403 | Không phải admin / ACL thiếu Users Write / user đích là root admin |
| 404 | User không tồn tại / key không thuộc user / identifier sai định dạng |

---

## 6. GET `/api/application/nodes/{node}/allocations/free`

Danh sách allocation của node có `server_id IS NULL`, có phân trang.

| Param | Rule | Ghi chú |
|-------|------|---------|
| `node` | `{node:id}` binding | Không tồn tại → 404 |
| `per_page` | `sometimes|integer|min:1|max:<allocations.max_per_page>` | Mặc định 50, trần mặc định 100. Sai → 422 |

ACL: **Allocations Read**. Route name: `api.application.allocations.free`.

**Response 200**

```json
{
  "object": "list",
  "data": [
    {
      "object": "allocation",
      "attributes": {
        "id": 101, "ip": "10.0.0.5", "alias": null,
        "port": 25565, "notes": null, "assigned": false
      }
    }
  ],
  "meta": {
    "pagination": {
      "total": 120, "count": 50, "per_page": 50,
      "current_page": 1, "total_pages": 3, "links": { "next": "..." }
    }
  }
}
```

Panel gốc cũng làm được việc này: `GET /api/application/nodes/{node}/allocations?filter[server_id]=false`. Endpoint của addon được giữ để tương thích ngược với v1/v2.

---

## 7. Cấu hình

`config/pterodactyl-client-api.php` (publish bằng `php artisan vendor:publish --provider="Byzic\PterodactylClientApi\PterodactylApiAddonServiceProvider" --tag="config"`):

| Key | Env | Mặc định | Ý nghĩa |
|-----|-----|----------|---------|
| `api_key.max_keys_per_user` | `CLIENT_API_MAX_KEYS_PER_USER` | `5` | Số account key tối đa của một user khi tạo qua addon |
| `api_key.allow_admin_targets` | `CLIENT_API_ALLOW_ADMIN_TARGETS` | `false` | Cho phép thao tác key của root admin |
| `allocations.max_per_page` | `CLIENT_API_ALLOCATIONS_MAX_PER_PAGE` | `100` | Trần `per_page` của endpoint free allocations |

Đổi env xong chạy `php artisan config:clear` (hoặc `config:cache` nếu panel cache config).

---

## 8. Chạy test

Test là integration test của panel: chạy trong một bản copy của panel có cài addon, với MySQL trong Docker. Chỉ cần Docker (trên Windows dùng Git Bash).

```bash
scripts/test-in-panel.sh ../Pterodactyl_Base/panel-1.15.1/panel-1.15.1
scripts/test-in-panel.sh <panel-source> --filter testKeyLimitIsApplied   # thêm tham số phpunit
```

- Script mount source panel ở chế độ chỉ đọc vào `/src` rồi copy bên trong container, không sửa thư mục nguồn; cài addon qua composer path repository; copy `tests/Integration/*.php` vào `tests/Integration/Api/Application/Users/` của panel rồi chạy phpunit.
- Biến môi trường: `PHP_VERSION` (mặc định `8.3`), `DB_IMAGE` (mặc định `mariadb:11`), `KEEP=1` giữ lại container DB và network để debug.
- File test: `tests/Integration/UserApiKeyControllerTest.php` và `tests/Integration/FreeAllocationControllerTest.php`.

---

## 9. Tích hợp với hosting-api

`hosting-api/src/modules/pterodactyl/account.service.ts` gọi 3 endpoint api-keys bằng application key `PANEL_KEY.USER_CREATE`:

| Hàm | Endpoint | Ghi chú |
|-----|----------|---------|
| `createClientKey` | POST | Gửi `description: 'byzic-provision'`, ghép `identifier + secret_token`, đúng với v2.1.0 |
| `listClientKeys` | GET | Cần key `user-create` có **Users Read & Write** (`r_users = 3`); chỉ có Write thì 403 |
| `revokeClientKey` | DELETE | |

Lưu ý:
- `ensurePanelAccount` hiện revoke **mọi** key của user trước khi tạo key mới, kể cả key user tự tạo. Nên chỉ revoke key có `description === 'byzic-provision'` (plan task 6.4).
- Giới hạn 5 key tính cả key user tự tạo. User đã tự tạo 5 key thì hosting-api nhận 400 và không tạo được key provisioning.
- User đã có panel account nhưng chưa có key: sau khi cài v2.1.0, vào Admin → Panel accounts → lọc `no_key` → Provision để tạo lại.

---

## 10. Bảo mật

Các lỗi trong v2.0.0 và cách v2.1.0 xử lý (chi tiết và dẫn chứng: `plans/261004-pterodactyl-client-api-fix/report.md`):

| Vấn đề ở v2.0.0 | v2.1.0 |
|-----------------|--------|
| Thiếu `application-api` → account key và session của user thường được nhận, ACL bị bỏ qua | Dùng đúng stack `/api/application` gốc; non-admin → 403 |
| Application key có `users: write` tạo được key cho root admin (chiếm quyền âm thầm) | Từ chối user đích là root admin (403), trừ khi bật `allow_admin_targets` |
| Token lưu bằng `sha256`, key tạo ra không đăng nhập được | `createToken()` của panel |
| Gọi `activity()` không tồn tại → không có audit log nào | `Activity` facade của panel; user đích thấy sự kiện |
| `Acl::DELETE`, `ForbiddenException`, `Application\ApiKeyTransformer` không tồn tại → 500 | Dùng các class có thật của panel |
| Đếm rồi tạo không khoá → vượt giới hạn khi gọi song song | `DB::transaction` + `lockForUpdate` |
| `per_page` không giới hạn, không validate | `integer|min:1|max:100` |
| Route param nối chuỗi vào rule `unique:` | Bỏ rule; tham số route qua binding và regex |

Rủi ro còn lại cần biết:
- **Application key `user-create` là bí mật cấp cao:** ai có nó tạo được client key cho mọi user không phải admin, tức điều khiển được mọi server của khách. Lưu key mã hoá (hosting-api đang làm vậy), giới hạn `allowed_ips` của key về IP của hosting-api, và chỉ cấp ACL Users cho key này.
- Root admin dùng account key hoặc session vẫn gọi được addon mà không qua ACL. Đây là hành vi gốc của mọi Application API trong panel, không phải riêng addon.
- Ngoài `throttle:api.application` và giới hạn số key, addon không giới hạn tần suất tạo key.
