# Return Request - Form Data Example

## Simplified Request Structure

الآن الـ request مبسط ويحتاج فقط:
- `purchase_order_item_id` (مطلوب)
- `return_quantity` (مطلوب)
- `quality_reason` (مطلوب)
- `files` (اختياري - الصور أو الملفات)

---

## Form Data Example (multipart/form-data)

### مثال بدون ملفات:
```
purchase_order_id: 550e8400-e29b-41d4-a716-446655440000
required_action: replacement
additional_notes: Items received in poor condition, need replacement

items[0][purchase_order_item_id]: 660e8400-e29b-41d4-a716-446655440001
items[0][return_quantity]: 2.5
items[0][quality_reason]: poor

items[1][purchase_order_item_id]: 660e8400-e29b-41d4-a716-446655440002
items[1][return_quantity]: 1
items[1][quality_reason]: normal
```

### مثال مع ملفات:
```
purchase_order_id: 550e8400-e29b-41d4-a716-446655440000
required_action: replacement
additional_notes: Items received in poor condition

items[0][purchase_order_item_id]: 660e8400-e29b-41d4-a716-446655440001
items[0][return_quantity]: 2.5
items[0][quality_reason]: poor
items[0][files][]: [FILE] /path/to/damaged-item-1.jpg
items[0][files][]: [FILE] /path/to/damaged-item-2.jpg

items[1][purchase_order_item_id]: 660e8400-e29b-41d4-a716-446655440002
items[1][return_quantity]: 1
items[1][quality_reason]: normal
items[1][files][]: [FILE] /path/to/evidence.jpg
```

---

## Postman Example

في Postman، استخدم **Body → form-data**:

| Key | Type | Value |
|-----|------|-------|
| `purchase_order_id` | Text | `550e8400-e29b-41d4-a716-446655440000` |
| `required_action` | Text | `replacement` |
| `additional_notes` | Text | `Items received in poor condition` |
| `items[0][purchase_order_item_id]` | Text | `660e8400-e29b-41d4-a716-446655440001` |
| `items[0][return_quantity]` | Text | `2.5` |
| `items[0][quality_reason]` | Text | `poor` |
| `items[0][files][]` | File | `[Select File]` |
| `items[0][files][]` | File | `[Select File]` |
| `items[1][purchase_order_item_id]` | Text | `660e8400-e29b-41d4-a716-446655440002` |
| `items[1][return_quantity]` | Text | `1` |
| `items[1][quality_reason]` | Text | `normal` |

---

## cURL Example

```bash
curl -X POST "https://api.example.com/api/purchase/returns" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "purchase_order_id=550e8400-e29b-41d4-a716-446655440000" \
  -F "required_action=replacement" \
  -F "additional_notes=Items received in poor condition" \
  -F "items[0][purchase_order_item_id]=660e8400-e29b-41d4-a716-446655440001" \
  -F "items[0][return_quantity]=2.5" \
  -F "items[0][quality_reason]=poor" \
  -F "items[0][files][]=@/path/to/image1.jpg" \
  -F "items[0][files][]=@/path/to/image2.jpg" \
  -F "items[1][purchase_order_item_id]=660e8400-e29b-41d4-a716-446655440002" \
  -F "items[1][return_quantity]=1" \
  -F "items[1][quality_reason]=normal"
```

---

## JSON Example (بدون ملفات)

```json
{
  "purchase_order_id": "550e8400-e29b-41d4-a716-446655440000",
  "required_action": "replacement",
  "additional_notes": "Items received in poor condition",
  "items": [
    {
      "purchase_order_item_id": "660e8400-e29b-41d4-a716-446655440001",
      "return_quantity": 2.5,
      "quality_reason": "poor"
    },
    {
      "purchase_order_item_id": "660e8400-e29b-41d4-a716-446655440002",
      "return_quantity": 1,
      "quality_reason": "normal"
    }
  ]
}
```

**ملاحظة:** عند استخدام JSON، لا يمكن رفع الملفات. يجب استخدام `multipart/form-data` لرفع الملفات.

---

## الحقول المطلوبة

### المستوى الأول:
- ✅ `purchase_order_id` (UUID) - **مطلوب**
- ✅ `required_action` - **مطلوب** (`replacement`, `cash_refund`, `credit_future_order`)
- ⚪ `additional_notes` - اختياري

### المستوى الثاني (items):
- ✅ `items` (array) - **مطلوب** (minimum 1 item)
- ✅ `items[*].purchase_order_item_id` (UUID) - **مطلوب**
- ✅ `items[*].return_quantity` (numeric, min: 0.001) - **مطلوب**
- ✅ `items[*].quality_reason` - **مطلوب** (`excellent`, `normal`, `poor`)
- ⚪ `items[*].files` (array of files) - اختياري
- ⚪ `items[*].files[*]` (file, max: 5MB) - اختياري

---

## ملاحظات مهمة

1. **البيانات التلقائية:** 
   - `item_name`, `item_logo`, `unit_of_measurement`, `unit_price` يتم جلبها تلقائياً من `PurchaseOrderItem`
   - لا حاجة لإرسالها في الـ request

2. **التحقق:**
   - `purchase_order_item_id` يجب أن يكون موجود في `purchase_order_items`
   - `purchase_order_item_id` يجب أن ينتمي لنفس `purchase_order_id`

3. **الملفات:**
   - الحد الأقصى لحجم الملف: 5MB (5120 KB)
   - يمكن رفع ملفات متعددة لكل item
   - نوع الملف: أي ملف (صور، مستندات، إلخ)

4. **Quality Reasons:**
   - `excellent` - جودة ممتازة لكن يحتاج return
   - `normal` - جودة عادية لكن يحتاج return
   - `poor` - جودة سيئة ويحتاج return

5. **Required Actions:**
   - `replacement` - استبدال بمنتج جيد
   - `cash_refund` - استرداد نقدي
   - `credit_future_order` - رصيد للطلب القادم
