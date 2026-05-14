---
description: Run PHPUnit tests
---

# Run Tests

// turbo-all

1. Run the full PHPUnit test suite:
```bash
./run_phpunit_tests.sh
```

2. Or run tests directly with specific options:
```bash
php bin/phpunit -c app/phpunit.xml.dist
```

3. For a specific test file, run:
```bash
php bin/phpunit -c app/phpunit.xml.dist --filter TestClassName
```
