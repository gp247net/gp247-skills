> 🌐 **Ngôn ngữ:** 🇻🇳 Tiếng Việt (hiện tại) · [🇬🇧 English](./README.md)

# Agent Skills của GP247 (gp247-skills)

## Giới thiệu

Đây là repo chung chứa các Agent Skill (`SKILL.md`) của GP247. Trang này là **mục lục**:
liệt kê toàn bộ skill trong repo và link tới từng skill. Mọi skill đều tuân theo chuẩn
skill GP247 (`247-skill`) — nội dung viết bằng tiếng Anh, có `description` kích hoạt bắt
buộc, và Block thông tin skill với mốc thời gian cập nhật.

## Danh sách skill

| Skill | Mô tả ngắn | Cập nhật lần cuối |
| --- | --- | --- |
| [gp247-plugin-create](./skills/gp247-plugin-create/SKILL.md) | Dựng và xây mới một plugin GP247 (admin TailAdmin + Livewire, gồm cả plugin thanh toán / vận chuyển / mã giảm giá), an toàn khi update. | 2026-10-03 |
| [gp247-plugin-v1-to-v2](./skills/gp247-plugin-v1-to-v2/SKILL.md) | Chuyển plugin GP247 viết cho Core 1.x sang định dạng plugin v2, chạy được trên core mà site đang dùng. | 2026-10-03 |
| [gp247-template-create](./skills/gp247-template-create/SKILL.md) | Dựng và xây mới một template (giao diện storefront) GP247, an toàn khi update. | 2026-10-03 |
| [gp247-extension-lifecycle](./skills/gp247-extension-lifecycle/SKILL.md) | Cài đặt, bật/tắt, nâng cấp, áp dụng cập nhật dữ liệu, gỡ bỏ plugin & template GP247 có sẵn qua CLI (`gp247:ext-*`). | 2026-10-03 |

## Skill tự nhận phiên bản core

Các skill **không ghi cứng** phiên bản gp247/core. Bước đầu tiên của mỗi skill chạy script chỉ-đọc
`scripts/gp247-probe.php` ngay trên site:

```bash
php <thư-mục-skill>/scripts/gp247-probe.php
```

Script in ra một đối tượng JSON gồm:

- phiên bản core **đúng như lúc core kiểm tương thích extension** (`config('gp247.core')`), kèm giá trị
  `requireCore` nên ghi vào `gp247.json`;
- `gp247/front`, `gp247/shop` có dùng được không — xét cả mã nguồn **lẫn** bảng trong cơ sở dữ liệu;
- danh sách lệnh `gp247:*` mà site đang có;
- các khả năng (tính năng, điểm cắm) mà core/front/shop hiện có.

Skill rẽ nhánh theo kết quả này. Nhờ vậy cùng một skill dùng được cho mọi phiên bản core, và không cũ đi
mỗi lần core lên phiên bản. Bốn skill mang bốn bản sao **giống hệt nhau** của script, để mỗi skill vẫn
chạy được khi cài riêng; khi sửa script, sửa cả bốn bản.

---

<sub>📅 **Cập nhật lần cuối:** 2026-10-03 · ✍️ **Tác giả (Author):** GP247</sub>
