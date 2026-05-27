Run deploys with:

```bash
sudo /var/www/rtls/deploy/deploy.sh
```

Or from the workspace copy:

```bash
sudo /home/agent/rtlsai/app/deploy/deploy.sh /var/www/rtls
```

The script:
- syncs code with `rsync --delete`
- preserves `.env`, `src/*.sqlite`, `logs/rtls.log`, and `logs/sessions/`
- restores Apache group write access for SQLite and logs
- runs `apachectl configtest` by default
