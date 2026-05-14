---
description: Database operations - regenerate schema, run migrations
---

# Database Operations

1. Regenerate and update the database schema:
```bash
bash regenerate_and_update.sh
```

2. Reset everything (careful - destructive!):
```bash
./reset_all.sh
```

3. Check Doctrine schema status:
```bash
php app/console doctrine:schema:validate
```

4. View pending migrations:
```bash
php app/console doctrine:migrations:status
```
