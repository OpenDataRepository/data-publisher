# Screenshot Session Resume Instructions

## Task Overview
Take responsive design screenshots at 3 screen sizes for 5 key pages, storing them in `/home/nate/data-publisher/design_screenshots/`

## Screen Sizes (3 passes)
1. **Phone**: iPhone 14 Pro Max (430 x 932) - User will set this
2. **Tablet**: User will set dimensions
3. **Desktop**: User will set dimensions

## Pages to Screenshot (5 per pass)
1. Login page - `https://www.odr.io/login`
2. Admin dashboard (database list) - `https://www.odr.io/admin/dashboard`
3. RRUFF Samples search page - `https://www.odr.io/rruff_sample`
4. Individual record view (Andradite) - `https://www.odr.io/rruff_sample#/view/640190/2010/eyJkdF9pZCI6IjczOCJ9/1`
5. Graph filter interface - Click on Raman Spectra graph on the record page

## Directory Structure
```
design_screenshots/
├── phone/       # iPhone 14 Pro Max screenshots
├── tablet/      # Tablet screenshots
└── desktop/     # Desktop screenshots
```

## File Naming Convention
- `01_login.png`
- `02_admin_dashboard.png`
- `03_rruff_search.png`
- `04_record_view.png`
- `05_graph_filter.png`

## Current Progress
- [x] Created `design_screenshots/phone/` directory
- [ ] Phone screenshots (0/5) - IN PROGRESS, waiting for Chrome DevTools MCP
- [ ] Tablet screenshots (0/5)
- [ ] Desktop screenshots (0/5)

## Login Credentials
- Email: claude@stoneumbrella.com
- Password: 1234****Aa

## Notes
- Browser MCP is connected at `https://www.odr.io/login`
- User is installing Chrome DevTools MCP for screenshot save capability
- Resume by taking screenshots starting with login page at phone size
