- need access for this endpoints to brand owner
  - GET /purchase/returns/in-progress => for owner get only status `escalated`
  - GET /purchase/returns/completed => for owner get only status `escalated_resolved` or `escalated_rejected`
  - GET /purchase/returns/{returnId}

- create this endpoint for brand owner
  - POST /brand-owner/purchase/returns/{returnId}/approve-escalation
    - POST /brand-owner/purchase/returns/{returnId}/reject-escalation
