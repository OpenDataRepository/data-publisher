# Data Publisher - Improvement Tasks

Areas identified for potential improvement, to be reviewed and prioritized.

---

## Code Quality

### [ ] Refactor Large Controllers
The following controllers are excessively large and should be broken into smaller, focused services:

| Controller | Size | Suggested Action |
|------------|------|------------------|
| `APIController.php` | 370KB | Extract into domain-specific API controllers |
| `PluginsController.php` | 258KB | Modularize plugin handling |
| `DisplaytemplateController.php` | 247KB | Extract template operations into services |
| `CSVImportController.php` | 235KB | Move import logic to dedicated services |
| `ValidationController.php` | 192KB | Create validation service layer |
| `LinkController.php` | 189KB | Simplify linking operations |

### [ ] Clean Up Backup Files
The `src/ODR/AdminBundle/Entity/` directory contains 67 `.php~` backup files that should be removed from the repository.

```bash
find src/ODR/AdminBundle/Entity -name "*.php~" -delete
```

---

## Framework & Dependencies

### [ ] Symfony Upgrade Path
**Current**: Symfony 3.4 (end-of-life December 2021)

Consider upgrade path:
1. Symfony 3.4 → 4.4 LTS
2. Symfony 4.4 → 5.4 LTS  
3. Symfony 5.4 → 6.4 LTS (current)

> [!WARNING]
> This is a significant undertaking due to deprecated bundle usage (JMSDiExtraBundle, SensioGeneratorBundle, etc.)

### [ ] PHP Version
Verify current PHP version and consider upgrading to PHP 8.x for performance and security.

---

## Testing

### [ ] Expand PHPUnit Coverage
- Current test structure exists but coverage appears limited
- Add unit tests for core services in `src/ODR/AdminBundle/Component/Service/`
- Add integration tests for API endpoints

### [ ] Browser/E2E Tests
- Existing Selenium tests in `tests/` directory
- Consider migration to modern framework (Playwright, Cypress)

---

## Documentation

### [x] Architecture Documentation
Created `ARCHITECTURE.md` with codebase overview.

### [ ] API Documentation
- Document REST API endpoints (v3, v4, v5)
- Consider OpenAPI/Swagger specification

### [ ] Developer Onboarding Guide
- Local development setup instructions
- Environment configuration guide

---

## Infrastructure

### [ ] Background Service Modernization
Node.js workers in `background_services/` could benefit from:
- TypeScript migration for type safety
- Better error handling and logging
- Health check endpoints

### [ ] Caching Strategy Review
- Review Redis/Memcached usage patterns
- Verify cache invalidation correctness

---

## Notes

*Add notes here as investigation progresses*
