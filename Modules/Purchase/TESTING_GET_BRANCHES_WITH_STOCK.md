# Testing: Get Branches with Stock for Internal Transfer

## Endpoint
`GET /api/v1/purchase/orders/branches`

## Request Parameters
```json
{
    "item_id": "uuid-of-branch-item",
    "quantity": 20,
    "exclude_branch_id": "uuid-of-current-branch",
    "min_availability": 60,  // Optional: minimum availability percentage
    "search": "branch name"   // Optional: search by branch name
}
```

## Expected Response Structure

```json
{
    "success": true,
    "data": [
        {
            "branch_id": "uuid",
            "branch": {
                "id": "uuid",
                "name": "Branch Name",
                "location": "Address",
                "image": "http://domain.com/storage/branches/image.jpg"
            },
            "branch_manager": {
                "id": "uuid",
                "name": "Manager Name",
                "image": "http://domain.com/storage/managers/image.jpg"
            },
            "available_quantity": 140.0,
            "availability_percentage": 100.0,
            "quality": "premium",
            "expiry_date": "2025-03-01",
            "cooling_status": false,
            "last_update": "2025-12-08 10:30:00",
            "distance": {
                "distance_km": 15.5,
                "estimated_hours": 0.26
            },
            "distance_km": 15.5,
            "estimated_hours": 0.26,
            "response_rate": 95.5,
            "rating": 4.5
        }
    ]
}
```

## Fields Description

### Branch Fields
- **branch.name**: Branch name
- **branch.image**: Full URL to branch image
- **branch.location**: Branch address/location

### Branch Manager Fields
- **branch_manager.name**: Manager name
- **branch_manager.image**: Full URL to manager image

### Item Details
- **available_quantity**: Available quantity from inventory
- **availability_percentage**: Percentage of required quantity available
- **quality**: Quality level (economy/standard/premium)
- **expiry_date**: Earliest expiry date
- **cooling_status**: Whether item requires cooling
- **last_update**: Last inventory update timestamp

### Store Details
- **distance**: Object with distance_km and estimated_hours
- **distance_km**: Distance in kilometers
- **estimated_hours**: Estimated travel time in hours (based on 60 km/h average)
- **response_rate**: Percentage of orders responded to within 24 hours (from last 6 months)
- **rating**: Branch rating (default: 4.5, can be enhanced with actual rating system)

## Test Cases

### Test Case 1: Basic Request
```bash
GET /api/v1/purchase/orders/branches?item_id=019b0001-1111-aaaa-bbbb-000000000001&quantity=20&exclude_branch_id=019af857-b645-72b0-afe3-e879090173de
```

### Test Case 2: With Filters
```bash
GET /api/v1/purchase/orders/branches?item_id=019b0001-1111-aaaa-bbbb-000000000001&quantity=20&exclude_branch_id=019af857-b645-72b0-afe3-e879090173de&min_availability=80&search=Main
```

### Test Case 3: No Results
```bash
GET /api/v1/purchase/orders/branches?item_id=non-existent-id&quantity=20&exclude_branch_id=019af857-b645-72b0-afe3-e879090173de
```

## Notes

1. **Distance Calculation**: Uses Haversine formula to calculate distance between branch coordinates
2. **Response Rate**: Calculated from orders in last 6 months, percentage of orders confirmed within 24 hours
3. **Rating**: Default value is 4.5, can be enhanced with actual rating system
4. **Fallback**: If BranchInventory not found, falls back to BranchItem data
5. **Coordinates Format**: Supports formats like "24.7136,46.6753" or "lat:24.7136,lng:46.6753"

