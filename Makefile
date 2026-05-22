.PHONY: setup seed reset shell logs pma plugin set-apikey

# First-time full setup: builds image (clones IOMAD ~5 min), starts containers,
# waits for install + seed to complete automatically via entrypoint.sh
setup:
	docker compose up -d --build
	@echo ""
	@echo "IOMAD is starting. Installation + seeding runs automatically."
	@echo "Watch progress: make logs"
	@echo "Web UI will be ready at http://localhost:8080 once Apache starts."

# Re-run seed (skipped automatically if .seeded flag exists; remove it first)
seed:
	docker compose exec iomad rm -f /var/moodledata/.seeded
	docker compose exec iomad php /seed/seed.php

# Tear down everything including volumes (clean slate)
reset:
	docker compose down -v
	docker compose up -d --build

# Open a bash shell in the IOMAD container
shell:
	docker compose exec iomad bash

# Follow IOMAD container logs
logs:
	docker compose logs -f iomad

# Reminder: phpMyAdmin URL
pma:
	@echo "phpMyAdmin: http://localhost:8081"

# Install/upgrade the block_iomad_claude plugin
plugin:
	docker compose exec iomad php /var/www/html/admin/cli/upgrade.php --non-interactive

# Set Anthropic API key from ANTHROPIC_API_KEY env var
set-apikey:
	docker compose exec iomad php /var/www/html/admin/cli/cfg.php \
	  --component=block_iomad_claude --name=apikey --set="$${ANTHROPIC_API_KEY}"
	docker compose exec iomad php /var/www/html/admin/cli/cfg.php \
	  --component=block_iomad_claude --name=model --set="$${CLAUDE_MODEL:-claude-sonnet-4-6}"

# Quick DB check: count quiz attempts
check:
	docker compose exec db mysql -u$${MYSQL_USER:-moodle} -p$${MYSQL_PASSWORD:-moodlesecret} $${MYSQL_DATABASE:-moodle} \
	  -e "SELECT 'companies' as entity, COUNT(*) as count FROM mdl_company \
	      UNION ALL SELECT 'courses', COUNT(*) FROM mdl_course WHERE id > 1 \
	      UNION ALL SELECT 'users (students)', COUNT(*) FROM mdl_user WHERE id > 2 \
	      UNION ALL SELECT 'quiz_attempts', COUNT(*) FROM mdl_quiz_attempts;"
