# IOMAD Claude Plugin

A multi-tenant IOMAD/Moodle demo that integrates the Anthropic Claude API to answer natural-language questions about school data — enrolments, quiz performance, student progress — directly from the dashboard.

## Credentials

| Role | Username | Password |
|------|----------|----------|
| Admin | `admin` | `Admin1234!` |
| Student (SHS) | `bart.simpson` | `Student123!` |

## Running locally

```bash
cp .env.example .env   # fill in ANTHROPIC_API_KEY
make setup             # builds image, installs IOMAD, seeds data (~5 min)
```

Then open http://localhost:8080.
