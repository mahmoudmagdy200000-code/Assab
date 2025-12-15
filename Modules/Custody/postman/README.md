# Postman API Collection

This directory contains the Postman collection for the Custody & Ledger Management API.

## 📥 Import Instructions

1. Open Postman
2. Click **Import** button (top left)
3. Select **File** tab
4. Choose `Custody_API_Collection.json`
5. Click **Import**

## 🔧 Setup Environment Variables

After importing, set up environment variables:

1. Create a new environment in Postman
2. Add the following variables:

| Variable       | Initial Value           | Current Value     | Description                     |
| -------------- | ----------------------- | ----------------- | ------------------------------- |
| `base_url`     | `http://localhost:8000` | Your API base URL | Base URL for all API requests   |
| `access_token` | (empty)                 | Your auth token   | Bearer token for authentication |

## 🔑 Authentication

All endpoints require authentication. To get an access token:

1. Login using your authentication endpoint
2. Copy the `access_token` from the response
3. Set it in the `access_token` environment variable

The collection automatically uses `{{access_token}}` in the Authorization header.

## 📋 Collection Structure

### 1. Personal Ledger Management

-   Get Personal Custody Balance Dashboard
-   Get Personal Balance Only
-   Get Transaction History (Daily View)
-   Get Transaction History (Detailed View with Filters)
-   Export Transactions as PDF

### 2. Custody Requests

-   List All Custody Requests
-   Get Request Details
-   Create Cash-in Request
-   Get Previous Requests History

### 3. Custody Transactions

-   List Custody Transactions (with filters)

### 4. Handover & Transfer

-   Handover to Branch/Owner Manager
-   Transfer to Custody
-   Get Recipients List

### 5. Balance Trends

-   Get Balance Trends

## 🧪 Testing Tips

1. **Start with Authentication**: Make sure you have a valid `access_token` set
2. **Test Balance First**: Use "Get Personal Balance Only" to verify authentication
3. **Check Responses**: Each request includes example responses for success and error cases
4. **Use Filters**: Try different filter combinations for transaction history
5. **Test File Uploads**: Use the "Create Cash-in Request" endpoint to test file uploads

## 📝 Notes

-   All dates should be in `YYYY-MM-DD` format
-   All amounts are in decimal format (e.g., `5000.00`)
-   File uploads support: PDF, JPG, JPEG, PNG, DOCX (max 5MB per file, max 5 files)
-   Transaction types are case-sensitive: `"Total Sales"`, `"Handover to Brand Owner"`, `"Transfer to Custody"`

## 🔄 Updating the Collection

If you make changes to the API, you can update this collection by:

1. Making changes in Postman
2. Exporting the collection again
3. Replacing this file
