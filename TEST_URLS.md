# TEST_URLS.md — Comprehensive URL List for Screenshots & Tests
## Open Data Repository — Data Publisher

> This file lists all routes for screenshot baseline capture and PHPUnit/Playwright test generation.
> URLs with `{param}` require substituting real IDs from the database.
> Auth requirements: `public` = no login, `user` = logged-in user, `admin` = admin role, `api` = JWT Bearer token.

---

## How to Use This File

### Screenshot Baseline (Playwright)
```bash
cd tests/screenshots
npx playwright test --update-snapshots
```

### PHPUnit API Tests
```bash
php bin/phpunit tests/
```

### Setting Test Variables
Before running tests, configure these values in `tests/config/test.env`:
```env
BASE_URL=https://odr.io
ADMIN_USERNAME=nate@opendatarepository.org
ADMIN_PASSWORD=HeUQ8PK!aVGWd6fh6tiK
API_USERNAME=nate@opendatarepository.org
API_PASSWORD=HeUQ8PK!aVGWd6fh6tiK
# Replace with real IDs from your database:
TEST_DATATYPE_ID=738
TEST_DATARECORD_ID=640191
TEST_DATAFIELD_ID=7069
TEST_THEME_ID=2010
TEST_SIDEBAR_LAYOUT_ID=1
TEST_GROUP_ID=1
TEST_USER_ID=2
TEST_DATASET_UUID=ddc5e9ba834ad596cc31aebb1225
TEST_TEMPLATE_UUID=xxxxxxx
```

---

## Section 1: Public Routes (No Authentication Required)

### Homepage & Search
| Route Name | URL | Method | Auth | Screenshot | Test |
|------------|-----|--------|------|------------|------|
| `odr_home` | `/` | GET | public | ✅ | ✅ |
| `odr_search` | `/{search_slug}` | GET | public | ✅ | ✅ |
| `odr_search_immediate` | `/{search_slug}/{search_string}` | GET | public | ✅ | ✅ |

### Authentication
| Route Name | URL | Method | Auth | Screenshot | Test |
|------------|-----|--------|------|------------|------|
| `fos_user_security_login` | `/login` | GET | public | ✅ | ✅ |
| `fos_user_security_login_check` | `/login_check` | POST | public | — | ✅ |
| `fos_user_security_logout` | `/logout` | GET | user | — | ✅ |
| `fos_user_resetting_request` | `/resetting/request` | GET | public | ✅ | ✅ |
| `fos_user_resetting_send_email` | `/resetting/send-email` | POST | public | — | ✅ |
| `odr_resetting_resend` | `/resetting/resend-email` | GET | public | ✅ | ✅ |
| `oauth_v2_auth_login` | `/oauth/v2/auth_login` | GET | public | ✅ | ✅ |
| `oauth_v2_auth_login_check` | `/oauth/v2/auth_login_check` | POST | public | — | ✅ |

### OAuth Provider Login
| Route Name | URL | Method | Auth | Screenshot | Test |
|------------|-----|--------|------|------------|------|
| `hwi_oauth_service_redirect` | `/connect/github` | GET | public | — | ✅ |
| `hwi_oauth_service_redirect` | `/connect/google` | GET | public | — | ✅ |

---

## Section 2: API Authentication Endpoints

### JWT Token Endpoints (POST — unauthenticated but requires credentials)
| Route Name | URL | Method | Body | Expected |
|------------|-----|--------|------|----------|
| `api_login_check_main` | `/api/v5/token` | POST | `{"username":"...","password":"..."}` | `{"token":"..."}` |
| `api_login_check` | `/api/v3/token` | POST | `{"username":"...","password":"..."}` | `{"token":"..."}` |
| `api_login_check_v4` | `/api/v4/token` | POST | `{"username":"...","password":"..."}` | `{"token":"..."}` |
| `fos_oauth_server_token` | `/oauth/v2/token` | POST | OAuth params | OAuth token response |

### Token Tests (should fail)
| Test | URL | Expected HTTP |
|------|-----|---------------|
| Missing credentials | `POST /api/v5/token` (empty body) | 400 or 401 |
| Wrong password | `POST /api/v5/token` (bad creds) | 401 |
| Missing token on protected route | `GET /api/v3/dataset/{uuid}` (no auth) | 401 |

---

## Section 3: API v3 Endpoints (JWT Bearer Auth Required)

Base: `/api/v3/`

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/v3/dataset/{dataset_uuid}` | GET | JWT | Get dataset metadata |
| `/api/v3/dataset/{dataset_uuid}/record/{record_uuid}` | GET | JWT | Get single record |
| `/api/v3/dataset/{dataset_uuid}/record` | POST | JWT | Create record |
| `/api/v3/dataset/{dataset_uuid}/record/{record_uuid}` | PUT | JWT | Update record |
| `/api/v3/dataset/{dataset_uuid}/record/{record_uuid}` | DELETE | JWT | Delete record |
| `/api/v3/dataset/{dataset_uuid}/record/{record_uuid}/file` | POST | JWT | Upload file |
| `/api/v3/dataset/{dataset_uuid}/search` | GET | JWT | Search records |
| `/api/v3/dataset/{dataset_uuid}/export` | GET | JWT | Export dataset |

---

## Section 4: API v4 Endpoints (JWT Bearer Auth Required)

Base: `/api/v4/`

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/v4/dataset/{dataset_uuid}` | GET | JWT | Get dataset (v4 format) |
| `/api/v4/dataset/{dataset_uuid}/record/{record_uuid}` | GET | JWT | Get record (v4 format) |
| `/api/v4/dataset/{dataset_uuid}/record` | POST | JWT | Create record |
| `/api/v4/dataset/{dataset_uuid}/record/{record_uuid}` | PUT | JWT | Update record |
| `/api/v4/dataset/{dataset_uuid}/record/{record_uuid}` | DELETE | JWT | Delete record |

### v4 Dataset-Specific Operations (API Key/JWT)
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/ima_list_rebuild` | GET | Rebuild IMA mineral list |
| `/ima_list_update` | GET | Update IMA mineral list (recent changes) |
| `/build_rruff_files` | GET | Build all RRUFF files |
| `/update_rruff_files` | GET | Update RRUFF files (recent changes) |
| `/rebuild_rruff_files` | GET | Rebuild RRUFF files |
| `/update_amcsd_files` | GET | Update AMCSD files |
| `/rebuild_amcsd_files` | GET | Rebuild AMCSD files |
| `/elastic` | GET | Seed Elasticsearch |

---

## Section 5: API v5 Endpoints (JWT Bearer Auth Required)

Base: `/api/v5/`

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/v5/dataset/{dataset_uuid}` | GET | JWT | Get dataset (JSON-LD) |
| `/api/v5/dataset/{dataset_uuid}/record/{record_uuid}` | GET | JWT | Get record (JSON-LD) |
| `/api/v5/dataset/{dataset_uuid}/record` | POST | JWT | Create record |
| `/api/v5/dataset/{dataset_uuid}/record/{record_uuid}` | PUT | JWT | Update record |
| `/api/v5/dataset/{dataset_uuid}/record/{record_uuid}` | DELETE | JWT | Delete record |
| `/network` | GET | JWT | Network/relationship search |
| `/elastic/record/{record_uuid}` | GET | JWT | Seed single Elasticsearch record |

---

## Section 6: Search & Statistics (Mixed Auth)

### Search Routes
| Route Name | URL | Method | Auth | Screenshot | Test |
|------------|-----|--------|------|------------|------|
| `odr_search_results` | `/search/results` | POST | user | — | ✅ |
| `odr_search_render` | `/search/display/{theme_id}/{search_key}/{offset}/{intent}` | GET | user | ✅ | ✅ |
| `odr_default_search_render` | `/search/render_default/{datatype_id}` | GET | user | ✅ | ✅ |
| `odr_legacy_search_results` | `/search/results/{search_key}` | GET | user | ✅ | ✅ |
| `odr_legacy_render` | `/search/render/{search_key}/{offset}/{source}` | GET | user | ✅ | ✅ |
| `odr_searchtest_get_results` | `/searchtest/{search_key}/{limit}/{offset}` | GET | user | — | ✅ |
| `odr_inline_link_search` | `/search/inline_link` | POST | user | — | ✅ |

### Remote Search
| Route Name | URL | Method | Auth | Screenshot | Test |
|------------|-----|--------|------|------------|------|
| `odr_remote_search_start` | `/remote_search` | GET | user | ✅ | ✅ |
| `odr_remote_search_select` | `/remote_search/{datatype_id}` | GET | user | ✅ | ✅ |
| `odr_remote_search_download` | `/remote_search/download/{minified}` | GET | user | — | ✅ |
| `odr_remote_search_examples` | `/remote_search/example/{type}` | GET | public | ✅ | ✅ |

### Statistics (API — JWT Required)
| Route Name | URL | Method | Auth | Test |
|------------|-----|--------|------|------|
| `odr_statistics_summary` | `/statistics/summary` | GET | JWT | ✅ |
| `odr_statistics_admin_dashboard` | `/statistics/dashboard` | GET | admin | ✅ |
| `odr_statistics_dashboard` | `/statistics/dashboard/{datatype_id}` | GET | user | ✅ |
| `odr_statistics_get_datatype` | `/statistics/datatype/{datatype_id}` | GET | JWT | ✅ |
| `odr_statistics_get_datarecord` | `/statistics/datarecord/{datarecord_id}` | GET | JWT | ✅ |
| `odr_statistics_geographic` | `/statistics/geographic` | GET | JWT | ✅ |
| `odr_statistics_log_view` | `/statistics/log_view` | POST | admin | ✅ |
| `odr_statistics_log_download` | `/statistics/log_download` | POST | admin | ✅ |

### Search Caching
| Route Name | URL | Method | Auth | Test |
|------------|-----|--------|------|------|
| `odr_precache_search` | `/precache/{datatype_id}` | GET | admin | ✅ |

---

## Section 7: Admin Interface (admin Role Required)

### Admin Homepage & DataType Management
| Route Name | URL | Method | Screenshot | Test |
|------------|-----|--------|------------|------|
| `odr_admin_homepage` | `/admin` | GET | ✅ | ✅ |
| `odr_list_types` | `/admin/type/list/databases` | GET | ✅ | ✅ |
| `odr_list_types` | `/admin/type/list/templates` | GET | ✅ | ✅ |
| `odr_list_types` | `/admin/type/list/datatemplates` | GET | ✅ | ✅ |
| `odr_datatype_properties` | `/admin/type/properties/{datatype_id}/0` | GET | ✅ | ✅ |
| `odr_datatype_landing` | `/admin/type/landing/{datatype_id}` | GET | ✅ | ✅ |
| `odr_create_type` | `/admin/type/create/0` | GET | ✅ | ✅ |
| `odr_list_copy_databases` | `/admin/type/copy/list` | GET | ✅ | ✅ |

### User Management
| Route Name | URL | Method | Screenshot | Test |
|------------|-----|--------|------------|------|
| `odr_user_list` | `/admin/user/list` | GET | ✅ | ✅ |
| `odr_admin_new_user_create` | `/admin/new_user/create` | GET | ✅ | ✅ |
| `odr_manage_user_roles` | `/admin/user/manage/roles` | GET | ✅ | ✅ |
| `odr_profile_edit` | `/admin/user/profile_edit/{user_id}` | GET | ✅ | ✅ |
| `odr_admin_change_password` | `/admin/user/change_password/{user_id}` | GET | ✅ | ✅ |
| `odr_manage_user_groups` | `/admin/user/managegroups/{user_id}` | GET | ✅ | ✅ |
| `odr_delete_user` | `/admin/user/delete/{user_id}` | GET | — | ✅ |

### Self-Profile
| Route Name | URL | Method | Screenshot | Test |
|------------|-----|--------|------------|------|
| `odr_self_profile_edit` | `/profile_edit` | GET | ✅ | ✅ |

### Group Management
| Route Name | URL | Method | Screenshot | Test |
|------------|-----|--------|------------|------|
| `odr_manage_groups` | `/admin/group/manange/{datatype_id}` | GET | ✅ | ✅ |
| `odr_group_properties` | `/admin/group/load/{group_id}` | GET | — | ✅ |
| `odr_manage_group_permissions` | `/admin/group/permissions/{group_id}` | GET | ✅ | ✅ |

### Plugin Management
| Route Name | URL | Method | Screenshot | Test |
|------------|-----|--------|------------|------|
| `odr_render_plugin_list` | `/admin/plugins/list` | GET | ✅ | ✅ |

### Data Import/Export
| Route Name | URL | Method | Screenshot | Test |
|------------|-----|--------|------------|------|
| CSV Import | `/admin/csvimport/*` | GET/POST | ✅ | ✅ |
| CSV Export | `/admin/csvexport/*` | GET/POST | ✅ | ✅ |
| XML Import | `/admin/xmlimport/*` | GET/POST | ✅ | ✅ |
| XSD Management | `/admin/xsd/*` | GET | ✅ | ✅ |

### JupyterHub Integration
| Route Name | URL | Method | Screenshot | Test |
|------------|-----|--------|------------|------|
| `odr_jupyterhub_list` | `/admin/jupyterhub/apps/{datatype_id}` | GET | ✅ | ✅ |

---

## Section 8: Data Display & Editing (user Role Required)

### Display / View
| Route Name | URL | Method | Screenshot | Test |
|------------|-----|--------|------------|------|
| Display record | `/view/{datarecord_id}/{datatype_id}` | GET | ✅ | ✅ |
| Display with theme | `/view/{datarecord_id}/{datatype_id}/{theme_id}` | GET | ✅ | ✅ |

### Edit
| Route Name | URL | Method | Screenshot | Test |
|------------|-----|--------|------------|------|
| Edit record | `/edit/{datarecord_id}` | GET | ✅ | ✅ |
| Plugin edit routes | `/edit/plugins/*` | GET/POST | ✅ | ✅ |

---

## Section 9: Design / Theme Interface (admin Role Required)

### Theme Designer
| Route Name | URL | Method | Screenshot | Test |
|------------|-----|--------|------------|------|
| `odr_modify_theme` | `/design/modify_view/{datatype_id}/{theme_id}` | GET | ✅ | ✅ |
| `odr_clone_theme` | `/design/copy_view/{theme_id}` | GET | — | ✅ |
| `odr_delete_custom_theme` | `/design/delete_view/{theme_id}` | GET | — | ✅ |

### Sidebar Layout Designer
| Route Name | URL | Method | Screenshot | Test |
|------------|-----|--------|------------|------|
| `odr_modify_sidebar_layout` | `/design/modify_layout/{datatype_id}/{sidebar_layout_id}` | GET | ✅ | ✅ |
| `odr_create_sidebar_layout` | `/design/create_layout/{datatype_id}` | GET | ✅ | ✅ |

---

## Section 10: Graph & Visualization (Mixed Auth)

| Route Name | URL | Method | Auth | Screenshot | Test |
|------------|-----|--------|------|------------|------|
| Graph static render | `/graph/static/{datafield_id}/{datarecord_ids}/{render_type}` | GET | user | ✅ | ✅ |
| View plugin (instrument usage) | `/view/plugins/rruff_instrument_usage/{datafield_id}/{datarecord_id}` | GET | user | ✅ | ✅ |
| View plugin (x-axis) | `/view/plugins/savexaxisdir/{...}` | POST | user | — | ✅ |
| Edit plugin (references) | `/edit/plugins/references/render/{datafield_id}/{datarecord_id}` | GET | admin | ✅ | ✅ |
| Edit plugin (delete graph) | `/edit/plugins/delete_individual_graph/{...}` | POST | admin | — | ✅ |

---

## Section 11: OAuth Management (admin Role Required)

| Route Name | URL | Method | Screenshot | Test |
|------------|-----|--------|------------|------|
| OAuth client list | `/profile/oauth_client/list` | GET | admin | ✅ | ✅ |
| OAuth client create | `/profile/oauth_client/create` | GET | admin | ✅ | ✅ |
| OAuth account connect | `/admin/oauth/connect/{service}` | GET | user | — | ✅ |
| OAuth account disconnect | `/admin/oauth/disconnect/{service}` | GET | user | — | ✅ |
| OAuth authorization | `/oauth/v2/auth` | GET | user | ✅ | ✅ |

---

## Section 12: Utility Routes

| Route Name | URL | Method | Auth | Test |
|------------|-----|--------|------|------|
| `odr_save_url` | `/save_url` | GET | user | ✅ |
| `odr_save_fragment` | `/save_fragment` | GET | user | ✅ |
| `odr_redirect` | `/redirect` | GET | user | ✅ |
| ApiBundle hello | `/hello/{name}` | GET | public | ✅ |

---

## Priority Test Matrix

### P0 — Critical (Must pass at every upgrade milestone)
- `POST /api/v5/token` — JWT token issuance
- `POST /api/v3/token` — JWT token issuance (legacy)
- `GET /api/v5/dataset/{uuid}` — Dataset retrieval (v5, JSON-LD)
- `GET /api/v3/dataset/{uuid}` — Dataset retrieval (v3, JSON)
- `POST /api/v3/dataset/{uuid}/record` — Record creation
- `GET /` — Homepage renders
- `GET /login` — Login page renders
- `POST /login_check` — Login succeeds
- `GET /admin` — Admin homepage renders (authenticated)
- `GET /admin/type/list/databases` — DataType list renders

### P1 — High (Run after each Symfony version upgrade)
- All search routes
- All display/view routes
- All edit routes
- Statistics API endpoints
- OAuth token flow
- User management CRUD

### P2 — Medium (Run at start and end of upgrade)
- Design/theme routes
- Import/export routes
- Plugin management
- Group management

### P3 — Low (Run at final upgrade completion)
- Graph rendering
- JupyterHub integration
- Remote search
- Legacy route compatibility

---

## Test File Organization

```
tests/
├── config/
│   └── test.env                          # Test configuration (copy from test.env.dist)
│
├── Api/
│   ├── AuthTest.php                      # Token endpoints (v3, v4, v5, OAuth)
│   ├── V3ApiTest.php                     # API v3 CRUD
│   ├── V4ApiTest.php                     # API v4 dataset operations
│   └── V5ApiTest.php                     # API v5 JSON-LD
│
├── Controller/
│   ├── PublicRoutesTest.php              # Homepage, login page, search pages
│   ├── AdminControllerTest.php           # Admin interface routes
│   ├── UserManagementTest.php            # User CRUD routes
│   ├── DataTypeControllerTest.php        # DataType management
│   ├── EditControllerTest.php            # Data editing routes
│   ├── DisplayControllerTest.php         # Data display routes
│   ├── SearchControllerTest.php          # Search routes
│   ├── StatisticsControllerTest.php      # Statistics API
│   ├── ImportExportControllerTest.php    # CSV/XML import/export
│   ├── ThemeControllerTest.php           # Theme designer
│   ├── OAuthControllerTest.php           # OAuth flows
│   └── GraphControllerTest.php           # Graph rendering
│
└── screenshots/
    ├── playwright.config.ts
    ├── baseline/                          # Reference screenshots (pre-upgrade)
    │   ├── public/
    │   ├── admin/
    │   ├── search/
    │   └── edit/
    └── specs/
        ├── public.spec.ts                 # Public page screenshots
        ├── auth.spec.ts                   # Auth flow screenshots
        ├── admin.spec.ts                  # Admin interface screenshots
        ├── search.spec.ts                 # Search result screenshots
        ├── edit.spec.ts                   # Edit interface screenshots
        └── api.spec.ts                    # API response snapshots
```
