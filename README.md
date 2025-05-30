# CRM Integration – Sample Code

This bundle demonstrates **two token-handling strategies** for connecting PHP to a recruitment CRM:

| Flow | File(s) | Purpose |
|------|---------|---------|
| OAuth 2 **Authorization-Code** | `Integration.php` | Browser-driven sign-in, token refresh, vacancy sync |
| **ROPC / service-to-service** | `Handoff_GenericCrm.php`, `GenericCrm_API.php` | Background worker that pushes candidates & files |

## What it proves

* clean separation of **Controller → Service → API-client** layers
* full token lifecycle managed with **league/oauth2-client** (acquire → refresh → persist → reuse)
* mapping & transport of **Candidates / Vacancies / Companies** between an ATS and our internal DB
* extensive logging & error handling, so failures are traceable

## Project background

The code lives inside a large, legacy platform that has grown over many years.  
Because of this, classes and especially **`CRMIntegration_ExternalCrm_Integration`** follows a structure dictated by the existing framework rather than a “green-field” design. You’ll notice a few obvious *hacks / shims*—they were added intentionally to work around historic gaps in the core application.

*The snapshot is functional and shows my approach, but it’s still an early-iteration draft that needs a final polishing pass before production.*
