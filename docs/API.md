# Pterodactyl Client API: tài liệu API (v2.2.0)

> Package: `nextscale-asia/pterodactyl-client-api` (namespace `Byzic\PterodactylClientApi`)
> Panel hỗ trợ: 1.13, 1.14 (Laravel 11), 1.15 (Laravel 12); PHP 8.2+
> Cập nhật: 2026-10-06

Addon thêm 7 endpoint vào Application API của Pterodactyl Panel. Panel gốc không có các endpoint này:

| # | Method | Path | Mục đích |
|---|--------|------|----------|
| 1 | `GET` | `/api/application/users/{user}/api-keys` | Liệt kê account API key (`ptlc_`) của một user |
| 2 | `POST` | `/api/application/users/{user}/api-keys` | Tạo account API key cho user |
| 3 | `DELETE` | `/api/application/users/{user}/api-keys/{identifier}` | Xoá một account API key của user |
| 4 | `GET` | `/api/application/nodes/{node}/allocations/free` | Liệt kê allocation chưa gán server của một node |
| 5 | `POST` | `/api/application/servers/{server}/transfer` | Bắt đầu transfer server sang node khác qua relay của agent (mục 7) |
| 6 | `GET` | `/api/application/servers/{server}/transfer` | Transfer mới nhất, kèm kiểm toàn vẹn khi `?verify=1` |
| 7 | `POST` | `/api/application/servers/{server}/transfer/cancel` | Đánh dấu thất bại một transfer đã chết |

> **Cảnh báo:** **không bao giờ** gọi `DELETE /api/application/servers/{server}/transfer`. Addon không có route đó; panel gốc có `DELETE /api/application/servers/{server:id}/{force?}`, nên path này là lệnh **XOÁ SERVER** của panel (`force = "transfer"`). Bản nháp 2.2.0 trước đây dùng DELETE; mọi client cũ phải chuyển sang `POST …/transfer/cancel`.

Panel gốc chỉ cho user tự quản lý key **của chính mình** (`/api/client/account/api-keys`). Addon cho phép một hệ thống bên ngoài (billing, provisioning) dùng application key của admin để tạo client key cho từng user, rồi dùng client key đó điều khiển server thay user (console, power, file).

Mọi endpoint đều dùng lại các hàm có sẵn của panel: `User::createToken()`, `Client\ApiKeyTransformer`, `Activity` facade và nhóm middleware `application-api`. Vì vậy key tạo qua addon giống hệt key user tự tạo trong trang tài khoản.

---

## 1. Cài đặt

Chạy trong thư mục panel (ví dụ `/var/www/pterodactyl`):

```bash
cd /var/www/pterodactyl
cp composer.json composer.json.bak && cp composer.lock composer.lock.bak

COMPOSER_ALLOW_SUPERUSER=1 composer require nextscale-asia/pterodactyl-client-api:^2.1 \
  --update-no-dev --optimize-autoloader
php artisan optimize:clear
chown -R www-data:www-data /var/www/pterodactyl/*   # user của nginx/apache/caddy

php artisan route:list --path=api-keys | grep application   # phải thấy 3 route của addon
```

- **Phải có `--update-no-dev`** trên panel production; nếu không, `composer require` cài thêm cả dev dependency của panel (PHPUnit…) vào `vendor/`.
- Rollback: khôi phục `composer.json.bak` / `composer.lock.bak`, chạy `composer install --no-dev --optimize-autoloader` và `php artisan optimize:clear`.

- Panel 1.13 / 1.14 (Laravel 11): chạy `composer require` như trên, **không** thêm `-W` / `--with-all-dependencies`. Composer hiện chặn mọi bản Laravel 11 vì security advisory, nên resolve lại `laravel/framework` sẽ lỗi; `require` thường giữ nguyên bản Laravel panel đã khoá.
- Service provider tự đăng ký qua `extra.laravel.providers`. `boot()` nạp `routes/api.php`, `register()` merge config vào key `pterodactyl-client-api`.
- ⚠️ **Nâng cấp panel sẽ gỡ mất addon.** Quy trình nâng cấp chuẩn của Pterodactyl giải nén bản mới đè lên thư mục panel (ghi đè `composer.json`) rồi chạy `composer install`. Sau mỗi lần nâng cấp panel phải chạy lại `composer require`, hoặc đưa bước này vào image/script nâng cấp.

### 1.1 Gỡ bản cũ và cài lại

Các bản trước từng được phát hành dưới nhiều tên package, và tag duy nhất trước 2.1.0 (`v1.0.0`) chứa code lỗi của 2.0.0. Cách làm: gỡ bản đang cài rồi cài 2.1.

**Bước 1: tìm bản đang cài** (trong thư mục panel):

```bash
composer show | grep -i -E "pterodactyl-api-addon|pterodactyl-client-api|panel-client-api"
grep -n -i -E "api-addon|client-api" composer.json          # mục require + repositories
composer config repositories                                 # repository kiểu path / vcs
grep -rn "PterodactylApiAddonServiceProvider" config/ bootstrap/ 2>/dev/null   # đăng ký thủ công
ls config/pterodactyl-client-api.php 2>/dev/null             # config đã publish
```

Các tên đã dùng: `rene-roscher/pterodactyl-api-addon` (1.x và 2.0.0 đầu tiên), `byzic/pterodactyl-client-api`, `NextScale-asia/Panel-Client-API`, `nextscale-asia/pterodactyl-client-api`.

**Bước 2: gỡ**

```bash
cp composer.json composer.json.bak && cp composer.lock composer.lock.bak

# dùng đúng tên mà `composer show` in ra ở bước 1
COMPOSER_ALLOW_SUPERUSER=1 composer remove rene-roscher/pterodactyl-api-addon --update-no-dev

# chỉ khi `composer config repositories` có mục path/vcs trỏ tới addon
composer config --unset repositories.<tên>

rm -f config/pterodactyl-client-api.php
php artisan optimize:clear
php artisan route:list --path=api-keys | grep application   # phải không còn dòng nào
```

- Nếu bước 1 tìm thấy `PterodactylApiAddonServiceProvider` trong `config/app.php` hoặc `bootstrap/providers.php` (cài tay, không qua Composer), thì xoá dòng đó và thư mục code đã chép vào, thay vì chạy `composer remove`.
- **Phải xoá config đã publish:** Laravel chỉ merge config package một cấp, nên khối `api_key` cũ sẽ thay toàn bộ khối mới (với file của 2.0.0, giới hạn key âm thầm thành 10 thay vì 5).
- Nếu đang dùng đúng tên `nextscale-asia/pterodactyl-client-api` thì có thể bỏ qua bước gỡ, chạy thẳng lệnh cài: Composer nâng cấp tại chỗ. Vẫn phải xoá config cũ.

**Bước 3: cài 2.1** bằng lệnh ở đầu mục 1.

**Sau khi gỡ 1.x:** bản 1.x không kiểm tra quyền khi tạo key (user bất kỳ tạo được key cho user khác, kể cả admin). Rà key của admin:

```sql
SELECT k.identifier, u.username, k.memo, k.created_at, k.last_used_at
FROM api_keys k JOIN users u ON u.id = k.user_id
WHERE k.key_type = 1 AND u.root_admin = 1 ORDER BY k.created_at DESC;
```

Key do 1.x tạo là key chuẩn của panel và vẫn dùng được. Bản 2.0.0 chưa từng tạo được key nào nên không có gì phải dọn.

### 1.2 Panel chạy bằng Docker (image chính thức)

Trong image `ghcr.io/pterodactyl/panel`, panel nằm ở `/app`; chỉ `/app/var`, log, cấu hình nginx và chứng chỉ là volume. `vendor/` và `config/` nằm trong image, nên:

- **Gỡ:** package cài bằng `docker compose exec panel composer require …` sẽ mất khi tạo lại container: `docker compose up -d --force-recreate panel`.
- **Cài:** `composer require` trong container đang chạy sẽ mất khi tạo lại container hoặc đổi image. Phải build image riêng:

```dockerfile
FROM ghcr.io/pterodactyl/panel:v1.15.1
# Giống các bước composer install trong image gốc.
RUN cp .env.example .env \
 && composer require nextscale-asia/pterodactyl-client-api:^2.1 --update-no-dev --optimize-autoloader \
 && rm -rf .env bootstrap/cache/*.php \
 && chown -R nginx:nginx .
```

Sau đó trỏ `image:` (hoặc `build:`) trong `docker-compose.yml` sang image này và chạy `docker compose up -d panel`. Mỗi lần đổi phiên bản panel phải build lại.

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
| GET transfer | Servers | Read | |
| POST transfer | Servers | Write | |
| POST transfer/cancel | Servers | Write | |

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
| `server:transfer.start` | Admin sở hữu application key | Server | `transfer_id` (không có id node/allocation, JWT, ticket) |
| `server:transfer.fail` | Admin sở hữu application key | Server | `transfer_id, reason` |

Activity của server được client API của panel hiện cho chủ server và subuser, nên sự kiện transfer chỉ ghi `transfer_id`; chi tiết node/allocation lấy qua `GET …/transfer`.

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

## 7. Transfer server: `/api/application/servers/{server}/transfer` (v2.2.0)

Panel gốc chỉ có nút Transfer trong trang admin (session + CSRF), không có Application API. Ba endpoint dưới đây làm **đúng** các bước của `Admin\Servers\ServerTransferController::transfer` và `Api\Remote\Servers\ServerTransferController::processFailedTransfer`, chỉ khác một chỗ: `url` mà Wings nguồn stream dữ liệu tới là **relay của wings-ops-agent trên chính node nguồn** (`relay_url`), thay cho địa chỉ công khai của node đích. Mọi thứ còn lại (bản ghi `server_transfers`, JWT, callback success/failure của Wings, đổi node/allocation, xoá bản ở node cũ) vẫn do code gốc của Panel và Wings xử lý.

| Method | Path | ACL | Mục đích |
|--------|------|-----|----------|
| `POST` | `/api/application/servers/{server}/transfer` | Servers Write | Bắt đầu transfer → 202 |
| `GET` | `/api/application/servers/{server}/transfer` | Servers Read | Transfer **mới nhất** (kể cả đã kết thúc); `?verify=1` thêm kiểm toàn vẹn |
| `POST` | `/api/application/servers/{server}/transfer/cancel` | Servers Write | Đánh dấu thất bại một transfer **đã chết** → 204 |

**Không có `DELETE …/transfer`.** Path đó khớp route `DELETE /api/application/servers/{server:id}/{force?}` của panel và sẽ **xoá server**. Test `testDeleteOnTheTransferPathIsThePanelsServerDelete` khẳng định controller của addon không bao giờ nhận DELETE.

`{server}` là id panel. Lỗi do addon tự trả có dạng giống lỗi gốc của panel, kèm `code` máy đọc được:

```json
{ "errors": [ { "code": "transfer_active", "status": "409", "detail": "...", "meta": { "node": "target" } } ] }
```

### 7.1 POST: bắt đầu transfer

```bash
curl -X POST "https://panel.example.com/api/application/servers/12/transfer" \
  -H "Authorization: Bearer ptla_..." -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"node_id": 3, "allocation_id": 501, "allocation_additional": [502],
       "relay_url": "http://127.0.0.1:781/relay/3/0123456789abcdef0123456789abcdef/api/transfers"}'
```

| Field | Rule |
|-------|------|
| `node_id` | bắt buộc, node tồn tại, **khác** node hiện tại của server |
| `allocation_id` | bắt buộc; allocation thuộc `node_id` và chưa gán server (`server_id IS NULL`) |
| `allocation_additional` | tuỳ chọn, mảng ≤ 100 id, không trùng nhau, không trùng `allocation_id`; cùng điều kiện như trên |
| `relay_url` | bắt buộc khi `transfer.require_relay = true` (mặc định). Phải khớp **chính xác** `http://127.0.0.1:{relay_port}/relay/{node_id}/{ticket}/api/transfers`: `relay_port` lấy từ config (mặc định `781`), `{node_id}` = node đích, `{ticket}` = 32 ký tự hex thường. Không chấp nhận `localhost`, `https`, user/pass trong URL, query string, ký tự xuống dòng |

Các bước (theo thứ tự):

1. Validate như bảng trên → 422. Node đích không đủ RAM/disk (`Node::isViable`, như panel) → 422 `node_not_viable`.
2. `Server::validateTransferState()` (chưa cài xong, đang restore backup, đang có transfer) → 409.
3. Trong một transaction: khoá dòng server (`SELECT … FOR UPDATE`) và gọi lại `validateTransferState()` trên bản ghi vừa khoá (kiểm lại cả ba điều kiện, 409); khoá các allocation đích và kiểm lại "vẫn còn trống" (422 nếu vừa bị lấy); tạo `ServerTransfer` y như panel; gán allocation đích cho server; ký JWT bằng khoá **node đích** (hết hạn 15 phút, `sub` = uuid server, scope `transfer`).
4. Gọi Wings nguồn `POST /api/servers/{uuid}/transfer` với body **giống từng trường** `DaemonTransferRepository::notify` của panel: `{server_id, url, token: "Bearer <jwt>", server: {uuid, start_on_completion: false}}`, chỉ `url` = `relay_url`. Timeout riêng `transfer.notifier_timeout` (mặc định 60 giây), vì Wings dừng server đồng bộ tới 15 giây trước khi trả lời, bằng đúng timeout Guzzle 15 giây của panel.
5. Kết quả gọi Wings:

| Wings trả | Addon làm | Response |
|-----------|-----------|----------|
| 2xx | commit | **202**, `meta.uncertain = false` |
| 4xx hoặc 500 (Wings từ chối rõ ràng, chưa bắt đầu gì) | **rollback** (không còn dòng `server_transfers`, allocation đích được nhả) | **502** `wings_rejected`, `meta.wings_status` |
| Timeout, lỗi mạng, 3xx, hoặc 502/503/504/52x (do proxy/tunnel đứng trước Wings) | **commit**, giữ nguyên dòng transfer | **202**, `meta.uncertain = true` |

   Addon **không đi theo redirect** khi gọi Wings (cả notify lẫn probe): đi theo sẽ gửi lại token node (và JWT transfer) tới địa chỉ trong `Location`. 3xx khi notify → `uncertain`, khi probe → `cannot_verify`.

   Đánh đổi có chủ ý (RT#6): dòng server và các allocation đích bị khoá suốt lúc chờ Wings trả lời (tới `notifier_timeout`, 60 giây), vì commit hay rollback phụ thuộc câu trả lời đó. Ghi khác vào server này (kể cả callback của Wings) phải chờ trong lúc ấy.

   Khi `uncertain = true`, Wings có thể đang stream. hosting-api phải đối soát (GET, heartbeat agent), không được coi là thất bại. Chắc chắn đã chết thì dùng `POST …/transfer/cancel`.

6. Ghi activity `server:transfer.start` (subject = server; property chỉ có `transfer_id`). Không ghi id node/allocation (subuser của server đọc được activity), không ghi JWT, `relay_url`/ticket.

**Response 202**

```json
{
  "object": "server_transfer",
  "attributes": {
    "id": 7, "server_id": 12, "old_node": 1, "new_node": 3,
    "old_allocation": 100, "new_allocation": 501,
    "old_additional_allocations": [], "new_additional_allocations": [502],
    "successful": null,
    "created_at": "2026-10-06T08:00:00+00:00", "updated_at": "2026-10-06T08:00:00+00:00"
  },
  "meta": { "uncertain": false }
}
```

`successful`: `null` = đang chạy, `true` = thành công, `false` = thất bại.

### 7.2 GET: transfer mới nhất và kiểm toàn vẹn

`GET …/transfer` trả transfer có id lớn nhất của server (kể cả đã kết thúc), cùng dạng `attributes` như trên. Chưa từng transfer → 404 `no_transfer`.

`GET …/transfer?verify=1` thêm `meta.integrity`, so DB panel với kết quả của transfer đó. hosting-api nên gọi sau **mọi** trạng thái kết thúc:

| `successful` | Server phải ở | Allocation chính | Phải thuộc server | Phải đã nhả khỏi server |
|---|---|---|---|---|
| `true` | `new_node` | `new_allocation` | allocation mới (chính + phụ) | allocation cũ |
| `false` | `old_node` | `old_allocation` | allocation cũ | allocation mới |
| `null` | `old_node` | `old_allocation` | cả cũ lẫn mới (mới đang được giữ chỗ) | |

```json
"meta": { "integrity": {
  "ok": false, "transfer_state": "successful",
  "server_node_id": 3, "expected_node_id": 3, "node_ok": true,
  "primary_allocation_id": 501, "expected_primary_allocation_id": 501, "primary_allocation_ok": false,
  "allocations": [
    { "allocation_id": 501, "expected": "assigned", "owned_by_this_server": false, "ok": false },
    { "allocation_id": 100, "expected": "released", "owned_by_this_server": false, "ok": true }
  ]
} }
```

`primary_allocation_ok` còn kiểm allocation chính nằm trên đúng node của server. "Đã nhả" nghĩa là không còn thuộc server này (đã được gán cho server khác vẫn tính là đúng). Mỗi allocation chỉ trả `owned_by_this_server` và `ok`; **không** trả id của server đang giữ allocation (có thể là server của khách khác). Ví dụ trên là trường hợp red team #4: huỷ chạy đua với success làm allocation chính của server có `server_id = NULL`.

### 7.3 POST cancel: đánh dấu thất bại một transfer đã chết

Panel không có job dọn transfer treo (`successful = NULL` khoá server vĩnh viễn). `POST …/transfer/cancel` làm đúng việc của `processFailedTransfer`: `successful = false` và nhả allocation đích. **Không xoá file** ở node nào (việc của agent, phase 3).

> **Không bao giờ gọi `DELETE …/servers/{server}/transfer`**: đó là lệnh xoá server của panel (xem đầu mục 7).

```bash
curl -X POST "https://panel.example.com/api/application/servers/12/transfer/cancel" \
  -H "Authorization: Bearer ptla_..." -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"confirm_agents_idle": true}'
```

Body: `{"confirm_agents_idle": true}` (bắt buộc). Các bước, theo thứ tự:

| Bước | Không đạt → |
|------|-------------|
| `confirm_agents_idle` = `true` (cam kết của bên gọi, xem dưới) | 422 |
| Có transfer `successful IS NULL` | 409 `no_pending_transfer` |
| Transfer đủ tuổi: `now > created_at + transfer.delete_min_age_seconds` (mặc định 1020 giây = JWT 15 phút + 2 phút lệch đồng hồ) | 409 `too_early`, `meta.retry_after` (giây, số nguyên) và header `Retry-After` |
| Wings **đích** `GET /api/servers/{uuid}`: chỉ 404 có body lỗi của Wings (`{"error": …}`) mới được hiểu là "đích không có server này" | 200 → 409 `transfer_active`; không gọi được, 3xx, 5xx, 401/403, 404 không phải của Wings → 409 `cannot_verify` (`meta.node = "target"`) |
| Wings **nguồn** `GET /api/servers/{uuid}` trả lời (2xx hoặc 404 của Wings) | 409 `cannot_verify` (`meta.node = "source"`) |
| Transaction: `SELECT … FOR UPDATE` dòng **server** trước, rồi dòng transfer; chỉ xử lý khi transfer vẫn `successful IS NULL` **và** `server.node_id = transfer.old_node` | 409 `no_pending_transfer` (callback của Wings vừa chạy trong lúc kiểm) |

Ví dụ `too_early`:

```json
{ "errors": [ { "code": "too_early", "status": "409",
  "detail": "The transfer token may still be valid; this transfer cannot be cancelled yet.",
  "meta": { "retry_after": 412 } } ] }
```

Không có tham số `force`. Thành công → 204 (body rỗng), ghi activity `server:transfer.fail` (`transfer_id`, `reason: marked-dead`). Allocation đích chỉ được nhả nếu vẫn đang thuộc server này.

**Vì sao khoá dòng server trước.** Callback `success()` của panel đọc transfer **không khoá**, rồi trong transaction của nó: nhả allocation cũ, cập nhật dòng server (node/allocation mới), cuối cùng đánh dấu transfer qua `$server->fresh()->transfer`. Khi cancel khoá dòng server trước:
- `success()` commit trước → cancel thấy server đã sang node mới / transfer đã xong → 409, không nhả gì.
- cancel commit trước → lệnh cập nhật server của `success()` phải chờ khoá; sau đó `->transfer` (chỉ lấy transfer `successful IS NULL`) là null, transaction của `success()` lỗi và rollback. Server không bao giờ nằm ở node mới với allocation chính đã bị nhả.

**Vì sao `too_early`.** JWT gửi cho Wings nguồn có hạn 15 phút. Trước khi hết hạn, Wings nguồn (hoặc relay thử lại) vẫn có thể mở stream tới đích **sau** khi cancel đã nhả allocation. Chờ hết hạn JWT + lệch đồng hồ thì không stream mới nào còn được đích chấp nhận. Chỉ hạ `delete_min_age_seconds` khi chấp nhận rủi ro đó.

**Giới hạn: `confirm_agents_idle` là cam kết của bên gọi, panel không kiểm được.** Response `GET /api/servers/{uuid}` của Wings chỉ có `state`, `is_suspended`, `utilization`, `configuration`, **không có cờ "transferring"**. Do đó:

- Phía **đích** đọc được gián tiếp: Wings đích đăng ký server ngay khi bắt đầu nhận stream và gỡ ra khi transfer thất bại. 404 = không có transfer đến (đã chết). 200 = đang nhận **hoặc** đã nhận xong (callback success có thể đang trên đường) → addon chọn an toàn: 409 `transfer_active`.
- Phía **nguồn** không đọc được: server luôn tồn tại ở nguồn dù có đang stream hay không. Lệnh duy nhất của Wings liên quan là `DELETE /api/servers/{uuid}/transfer`, nhưng lệnh đó **huỷ** transfer chứ không hỏi trạng thái. Addon chỉ kiểm Wings nguồn còn trả lời.
- Vì vậy phần "nguồn đã ngừng stream" do **hosting-api** xác nhận: trước khi gọi cancel, hosting-api kiểm heartbeat mới nhất của **cả hai** agent không còn relay nào cho uuid này (plan 261006 RT#4, phase 3), rồi gửi `confirm_agents_idle: true`. Gọi cancel khi chưa kiểm là sai hợp đồng API.

Nếu Wings đích giữ server mãi (200) hoặc Wings nguồn kẹt cờ `transferring` sau sự cố, addon không gỡ được; phải xử lý ở Wings (restart Wings hoặc patch fork, chưa chốt).

### 7.4 Bảo mật

- Với `require_relay = true`, JWT transfer chỉ đi tới `127.0.0.1:{relay_port}` trên node nguồn; không có đường nào qua endpoint này để gửi JWT ra ngoài loopback. `relay_port` < 1024 để process không có quyền root trên node không chiếm được cổng khi agent tắt.
- Ticket trong `relay_url` là bí mật một lần của relay: không ghi vào activity log, không trả lại trong response.
- Key `ptla_` có Servers Write gọi được endpoint này. Key có Nodes Write vẫn đổi được FQDN node rồi dùng nút Transfer gốc của panel để đẩy dữ liệu ra ngoài, nên addon **không** làm cho key bị lộ trở nên vô hại. Nên dùng một application key riêng cho transfer, chỉ cấp Servers Read & Write, giới hạn `allowed_ips` về IP của hosting-api.
- Nút Transfer trong trang admin của panel vẫn hoạt động như cũ (đi thẳng tới FQDN node đích, không qua relay). Đây là ghi chú vận hành, addon không chặn.
- Addon không đi theo redirect khi gọi Wings (token node và JWT không bao giờ bị gửi tới `Location` của một 3xx).
- `GET …/transfer?verify=1` không trả id của server khác (chỉ `owned_by_this_server`).

**Yêu cầu vận hành trên node** (giả định của mô hình relay):

- Giữ `net.ipv4.ip_unprivileged_port_start = 1024` (mặc định của kernel). Hạ giá trị này (một số image container/rootless đặt `0`) thì process không có quyền root bind được cổng relay `781` khi agent tắt và nhận JWT.
- Wings phải chạy trong **host network namespace** (cài trực tiếp, hoặc container `network_mode: host`). `127.0.0.1` trong `relay_url` là loopback của namespace nơi Wings chạy; Wings trong network namespace riêng sẽ gọi loopback của chính nó, không tới relay của agent.
- hosting-api phải **luôn gửi `relay_url`** trong body POST. Không dựa vào việc tắt `require_relay` để "dùng tạm" URL gốc của panel: khi đó JWT đi thẳng ra FQDN node đích.

---

## 8. Cấu hình

`config/pterodactyl-client-api.php` (publish bằng `php artisan vendor:publish --provider="Byzic\PterodactylClientApi\PterodactylApiAddonServiceProvider" --tag="config"`):

| Key | Env | Mặc định | Ý nghĩa |
|-----|-----|----------|---------|
| `api_key.max_keys_per_user` | `CLIENT_API_MAX_KEYS_PER_USER` | `5` | Số account key tối đa của một user khi tạo qua addon |
| `api_key.allow_admin_targets` | `CLIENT_API_ALLOW_ADMIN_TARGETS` | `false` | Cho phép thao tác key của root admin |
| `allocations.max_per_page` | `CLIENT_API_ALLOCATIONS_MAX_PER_PAGE` | `100` | Trần `per_page` của endpoint free allocations |
| `transfer.require_relay` | `CLIENT_API_TRANSFER_REQUIRE_RELAY` | `true` | Bắt buộc `relay_url`. Tắt thì thiếu `relay_url` sẽ dùng URL gốc của panel (FQDN node đích); `relay_url` nếu có vẫn phải hợp lệ |
| `transfer.relay_port` | `CLIENT_API_TRANSFER_RELAY_PORT` | `781` | Cổng loopback của relay agent, phải khớp cấu hình agent |
| `transfer.notifier_timeout` | `CLIENT_API_TRANSFER_NOTIFIER_TIMEOUT` | `60` | Timeout (giây) khi gọi Wings nguồn bắt đầu transfer |
| `transfer.probe_timeout` | `CLIENT_API_TRANSFER_PROBE_TIMEOUT` | `10` | Timeout (giây) mỗi lần cancel hỏi Wings `GET /api/servers/{uuid}` |
| `transfer.delete_min_age_seconds` | `CLIENT_API_TRANSFER_DELETE_MIN_AGE_SECONDS` | `1020` | Tuổi tối thiểu (giây) của transfer trước khi cancel được chấp nhận (JWT 15 phút + 2 phút lệch đồng hồ). Nhỏ hơn → 409 `too_early` |

Đổi env xong chạy `php artisan config:clear` (hoặc `config:cache` nếu panel cache config).

---

## 9. Chạy test

Test là integration test của panel: chạy trong một bản copy của panel có cài addon, với MySQL trong Docker. Chỉ cần Docker (trên Windows dùng Git Bash).

```bash
scripts/test-in-panel.sh ../Pterodactyl_Base/panel-1.15.1/panel-1.15.1
scripts/test-in-panel.sh <panel-source> --filter testKeyLimitIsApplied   # thêm tham số phpunit
```

- Script mount source panel ở chế độ chỉ đọc vào `/src` rồi copy bên trong container, không sửa thư mục nguồn; cài addon qua composer path repository; copy `tests/Integration/*.php` vào `tests/Integration/Api/Application/Users/` của panel rồi chạy phpunit.
- Biến môi trường: `PHP_VERSION` (mặc định `8.3`), `DB_IMAGE` (mặc định `mariadb:11`), `KEEP=1` giữ lại container DB và network để debug.
- File test: `tests/Integration/UserApiKeyControllerTest.php`, `tests/Integration/FreeAllocationControllerTest.php`, `tests/Integration/ServerTransferControllerTest.php`. Test transfer giả lập Wings bằng `Http::fake()` (addon gọi Wings qua HTTP client của Laravel), không cần Wings thật.

---

## 10. Tích hợp với hosting-api

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

## 11. Bảo mật

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
