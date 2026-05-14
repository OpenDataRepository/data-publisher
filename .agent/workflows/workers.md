---
description: Start or restart background job workers
---

# Background Workers

1. Start all background job workers:
```bash
./start_jobs.sh
```

2. For debug mode with more verbose output:
```bash
./start_jobs_debug.sh
```

3. Restart just the export workers:
```bash
./restart_export_workers.sh
```

4. Worker status can be checked in `background_services/graph_renderer.log`
