# Return Management API - Example Request Bodies

## Base URL
All endpoints are prefixed with `/api/purchase/returns`

---

## 1. Create Return (POST `/api/purchase/returns`)

**Description:** Create a new return order for a closed purchase order.

**Request Body (multipart/form-data):**
```json
{
  "purchase_order_id": "550e8400-e29b-41d4-a716-446655440000",
  "required_action": "replacement",
  "additional_notes": "Items received in poor condition, need replacement",
  "items": [
    {
      "purchase_order_item_id": "660e8400-e29b-41d4-a716-446655440001",
      "item_name": "Premium Rice 50kg",
      "item_logo": "https://example.com/images/rice.jpg",
      "return_quantity": 2.5,
      "unit": "kg",
      "quality_reason": "poor",
      "unit_price": 150.00,
      "notes": "Bags were torn and rice was exposed to moisture",
      "files": [
        // File uploads - use multipart/form-data
        // Field name: items[0][files][]
      ]
    },
    {
      "purchase_order_item_id": "660e8400-e29b-41d4-a716-446655440002",
      "item_name": "Cooking Oil 20L",
      "item_logo": "https://example.com/images/oil.jpg",
      "return_quantity": 1,
      "unit": "liter",
      "quality_reason": "normal",
      "unit_price": 200.00,
      "notes": "Expiry date is too close",
      "files": [
        // File uploads - use multipart/form-data
        // Field name: items[1][files][]
      ]
    }
  ]
}
```

**cURL Example:**
```bash
curl -X POST "https://api.example.com/api/purchase/returns" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: multipart/form-data" \
  -F "purchase_order_id=550e8400-e29b-41d4-a716-446655440000" \
  -F "required_action=replacement" \
  -F "additional_notes=Items received in poor condition" \
  -F "items[0][purchase_order_item_id]=660e8400-e29b-41d4-a716-446655440001" \
  -F "items[0][item_name=Premium Rice 50kg" \
  -F "items[0][return_quantity=2.5" \
  -F "items[0][unit=kg" \
  -F "items[0][quality_reason=poor" \
  -F "items[0][unit_price=150.00" \
  -F "items[0][notes=Bags were torn" \
  -F "items[0][files][]=@/path/to/image1.jpg" \
  -F "items[0][files][]=@/path/to/image2.jpg" \
  -F "items[1][purchase_order_item_id]=660e8400-e29b-41d4-a716-446655440002" \
  -F "items[1][item_name=Cooking Oil 20L" \
  -F "items[1][return_quantity=1" \
  -F "items[1][unit=liter" \
  -F "items[1][quality_reason=normal" \
  -F "items[1][unit_price=200.00" \
  -F "items[1][files][]=@/path/to/image3.jpg"
```

**Required Action Values:**
- `replacement` - Replacement with Good Product
- `cash_refund` - Cash Refund
- `credit_future_order` - Credit for Future Order

**Quality Reason Values:**
- `excellent` - Excellent
- `normal` - Normal
- `poor` - Poor

**Unit Values:**
- `kg` - Kilogram
- `pk` - Pack
- `unit` - Unit
- `box` - Box
- `liter` - Liter
- `piece` - Piece

---

## 2. Save as Draft (POST `/api/purchase/returns/draft`)

**Description:** Save a return order as draft for later editing.

**Request Body:** Same as Create Return (multipart/form-data)

**cURL Example:**
```bash
curl -X POST "https://api.example.com/api/purchase/returns/draft" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: multipart/form-data" \
  -F "purchase_order_id=550e8400-e29b-41d4-a716-446655440000" \
  -F "required_action=cash_refund" \
  -F "items[0][item_name=Premium Rice 50kg" \
  -F "items[0][return_quantity=2.5" \
  -F "items[0][quality_reason=poor" \
  -F "items[0][unit_price=150.00"
```

---

## 3. Update Return (PUT `/api/purchase/returns/{id}`)

**Description:** Update a draft return order.

**Request Body (multipart/form-data):**
```json
{
  "required_action": "cash_refund",
  "additional_notes": "Updated notes after review",
  "items": [
    {
      "id": "770e8400-e29b-41d4-a716-446655440003",
      "return_quantity": 3.0,
      "quality_reason": "poor",
      "notes": "Updated notes for this item",
      "files": [
        // New files to add
      ],
      "deleted_files": [
        // Array of file paths to delete
        "purchase/documents/old-file.jpg"
      ]
    },
    {
      // New item to add (without id)
      "item_name": "New Item",
      "return_quantity": 1,
      "quality_reason": "normal",
      "unit_price": 100.00
    }
  ]
}
```

**cURL Example:**
```bash
curl -X PUT "https://api.example.com/api/purchase/returns/770e8400-e29b-41d4-a716-446655440000" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: multipart/form-data" \
  -F "required_action=cash_refund" \
  -F "items[0][id]=770e8400-e29b-41d4-a716-446655440003" \
  -F "items[0][return_quantity=3.0" \
  -F "items[0][files][]=@/path/to/new-image.jpg" \
  -F "items[0][deleted_files][]=purchase/documents/old-file.jpg"
```

---

## 4. Submit Return (POST `/api/purchase/returns/{id}/submit`)

**Description:** Submit a draft return order for supplier review.

**Request Body:** Empty (no body required)

**cURL Example:**
```bash
curl -X POST "https://api.example.com/api/purchase/returns/770e8400-e29b-41d4-a716-446655440000/submit" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## 5. Accept Rejection (POST `/api/purchase/returns/{id}/accept-rejection`)

**Description:** Accept a supplier's rejection and close the return.

**Request Body:** Empty (no body required)

**cURL Example:**
```bash
curl -X POST "https://api.example.com/api/purchase/returns/770e8400-e29b-41d4-a716-446655440000/accept-rejection" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## 6. Escalate Return (POST `/api/purchase/returns/{id}/escalate`)

**Description:** Escalate a rejected return to Brand Owner.

**Request Body (application/json):**
```json
{
  "reason": "Supplier rejected without valid justification. Items were clearly damaged upon delivery. Requesting Brand Owner review."
}
```

**cURL Example:**
```bash
curl -X POST "https://api.example.com/api/purchase/returns/770e8400-e29b-41d4-a716-446655440000/escalate" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "reason": "Supplier rejected without valid justification"
  }'
```

---

## 7. Delete Draft (DELETE `/api/purchase/returns/{id}/draft`)

**Description:** Permanently delete a draft return order.

**Request Body:** Empty (no body required)

**cURL Example:**
```bash
curl -X DELETE "https://api.example.com/api/purchase/returns/770e8400-e29b-41d4-a716-446655440000/draft" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## 8. Get In-Progress Returns (GET `/api/purchase/returns/in-progress`)

**Description:** Get list of returns in progress.

**Query Parameters:**
- `per_page` (optional, default: 15) - Number of items per page
- `page` (optional, default: 1) - Page number

**Request Body:** Not applicable (GET request)

**cURL Example:**
```bash
curl -X GET "https://api.example.com/api/purchase/returns/in-progress?per_page=20&page=1" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## 9. Get Draft Returns (GET `/api/purchase/returns/drafts`)

**Description:** Get list of draft returns.

**Query Parameters:**
- `per_page` (optional, default: 15) - Number of items per page
- `page` (optional, default: 1) - Page number

**cURL Example:**
```bash
curl -X GET "https://api.example.com/api/purchase/returns/drafts?per_page=20" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## 10. Get Completed Returns (GET `/api/purchase/returns/completed`)

**Description:** Get list of completed returns.

**Query Parameters:**
- `per_page` (optional, default: 15) - Number of items per page
- `page` (optional, default: 1) - Page number

**cURL Example:**
```bash
curl -X GET "https://api.example.com/api/purchase/returns/completed?per_page=20" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## 11. Get Return Details (GET `/api/purchase/returns/{id}`)

**Description:** Get detailed information about a specific return order.

**Request Body:** Not applicable (GET request)

**cURL Example:**
```bash
curl -X GET "https://api.example.com/api/purchase/returns/770e8400-e29b-41d4-a716-446655440000" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response Example:**
```json
{
  "success": true,
  "message": "Return details retrieved successfully",
  "data": {
    "id": "770e8400-e29b-41d4-a716-446655440000",
    "return_number": "RO-20250123-ABCD",
    "return_date": "2025-01-23",
    "status": "pending",
    "status_label": "Pending",
    "status_color": "#F59E0B",
    "required_action": "replacement",
    "required_action_label": "Replacement with Good Product",
    "total_return_amount": 375.00,
    "additional_notes": "Items received in poor condition",
    "purchase_order": {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "order_number": "PO-20250120-1234",
      "status": "closed",
      "status_label": "Closed"
    },
    "supplier": {
      "id": "880e8400-e29b-41d4-a716-446655440000",
      "name": "ABC Suppliers",
      "image": "https://example.com/suppliers/abc.jpg",
      "status": "online",
      "status_label": "Online",
      "contact_methods": ["whatsapp", "email", "app"],
      "average_response_time_hours": 2.5,
      "response_rate_percentage": 95.5
    },
    "items": [
      {
        "id": "990e8400-e29b-41d4-a716-446655440001",
        "item_name": "Premium Rice 50kg",
        "item_logo": "https://example.com/images/rice.jpg",
        "return_quantity": 2.5,
        "unit_of_measurement": "kg",
        "quality_reason": "poor",
        "quality_reason_label": "Poor",
        "unit_price": 150.00,
        "return_amount": 375.00,
        "files": [
          "https://example.com/storage/purchase/documents/file1.jpg",
          "https://example.com/storage/purchase/documents/file2.jpg"
        ],
        "file_count": 2,
        "notes": "Bags were torn"
      }
    ],
    "timelines": [
      {
        "id": "aa0e8400-e29b-41d4-a716-446655440000",
        "event_type": "return_submitted",
        "event_label": "Return Submitted",
        "title": "Return Submitted",
        "description": "Return order was submitted for review",
        "actor": {
          "id": "bb0e8400-e29b-41d4-a716-446655440000",
          "name": "John Doe",
          "image": "https://example.com/users/john.jpg",
          "role": "Branch Manager"
        },
        "occurred_at": "2025-01-23 10:30:00",
        "occurred_at_human": "2 hours ago"
      }
    ],
    "created_at": "2025-01-23 10:00:00",
    "submitted_at": "2025-01-23 10:30:00"
  }
}
```

---

## 12. Get Return Timeline (GET `/api/purchase/returns/{id}/timeline`)

**Description:** Get timeline events for a return order.

**Request Body:** Not applicable (GET request)

**cURL Example:**
```bash
curl -X GET "https://api.example.com/api/purchase/returns/770e8400-e29b-41d4-a716-446655440000/timeline" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response Example:**
```json
{
  "success": true,
  "message": "Return timeline retrieved successfully",
  "data": [
    {
      "id": "aa0e8400-e29b-41d4-a716-446655440000",
      "event_type": "return_submitted",
      "event_label": "Return Submitted",
      "event_icon": "rotate-ccw",
      "old_status": "draft",
      "new_status": "pending",
      "actor": {
        "id": "bb0e8400-e29b-41d4-a716-446655440000",
        "name": "John Doe",
        "image": "https://example.com/users/john.jpg",
        "role": "Branch Manager"
      },
      "title": "Return Submitted",
      "description": "Return order was submitted for review",
      "metadata": null,
      "occurred_at": "2025-01-23 10:30:00",
      "occurred_at_human": "2 hours ago"
    },
    {
      "id": "cc0e8400-e29b-41d4-a716-446655440000",
      "event_type": "return_created",
      "event_label": "Return Created",
      "event_icon": "rotate-ccw",
      "old_status": null,
      "new_status": "draft",
      "actor": {
        "id": "bb0e8400-e29b-41d4-a716-446655440000",
        "name": "John Doe",
        "image": "https://example.com/users/john.jpg",
        "role": "Branch Manager"
      },
      "title": "Return Created",
      "description": "Return order RO-20250123-ABCD was created",
      "metadata": null,
      "occurred_at": "2025-01-23 10:00:00",
      "occurred_at_human": "2 hours ago"
    }
  ]
}
```

---

## Notes

1. **File Uploads:** When uploading files, use `multipart/form-data` content type. Files should be uploaded as arrays using the format `items[index][files][]`.

2. **Authentication:** All endpoints require authentication. Include the Bearer token in the Authorization header.

3. **Validation:**
   - Returns can only be created for orders with status `CLOSED`
   - Only draft returns can be updated or deleted
   - Only draft returns can be submitted

4. **Status Flow:**
   - `draft` → `pending` (on submit)
   - `pending` → `approved` (supplier approves)
   - `pending` → `rejected` (supplier rejects)
   - `rejected` → `closed` (accept rejection)
   - `rejected` → `escalated` (escalate)
   - `escalated` → `resolved` (brand owner resolves)
   - `approved` → `resolved` (brand owner resolves)

5. **File Size Limit:** Maximum file size is 5MB (5120 KB) per file.

6. **Quality Reasons:**
   - `excellent` - Item quality is excellent but return is needed for other reasons
   - `normal` - Item quality is normal but return is needed
   - `poor` - Item quality is poor and return is needed
