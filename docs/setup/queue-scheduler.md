# Queue and scheduler setup

The foundation uses Laravel's database queue driver with its standard jobs, batches, and failed-jobs migrations. No product jobs have been added. In development, process one item with `php artisan queue:work --once` or run a worker with `php artisan queue:work --tries=3 --timeout=90`.

In production, prefer a host-managed persistent worker with bounded retries and a timeout appropriate to future work. If cPanel does not allow persistent workers, confirm a cron-driven `php artisan queue:work --stop-when-empty --tries=3 --timeout=90` approach with the host and monitor queue age/failure behavior. Do not assume `queue:listen` is an available production process manager.

The Laravel scheduler is enabled through `routes/console.php` and `schedule:list`; there are no Phase 1 scheduled product tasks. For cPanel, configure one cron job to run every minute, using absolute paths, for example:

```text
* * * * * /usr/local/bin/php /home/ACCOUNT/private-app/artisan schedule:run >> /dev/null 2>&1
```

Replace both paths with the actual cPanel PHP binary and private application path. The host may require a different cron shell or PHP binary. Verify the entry in the hosting control panel and review Laravel logs after the first scheduler run.
