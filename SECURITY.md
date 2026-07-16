# Security Policy

## Supported Versions

Attachment Scout is a small, independently maintained WordPress plugin. Security updates are provided for the most recent release only.

| Version           | Supported |
| ----------------- | --------- |
| 1.1.x             | ✅         |
| 1.0.x and earlier | ❌         |

Users should update to the latest available version before reporting an issue.

## Reporting a Vulnerability

Please do not report security vulnerabilities through a public GitHub issue.

Instead, use GitHub's private vulnerability reporting feature:

1. Open the **Security** tab in this repository.
2. Select **Report a vulnerability**.
3. Provide a clear description of the issue and the steps needed to reproduce it.

When possible, include:

* The affected Attachment Scout version
* The WordPress and PHP versions being used
* Steps to reproduce the vulnerability
* The potential security impact
* Any suggested fix or mitigation
* Relevant screenshots, logs, or example code

Please do not include real credentials, private website data, database contents, or other sensitive information in the report.

Reports will be reviewed as availability permits. The maintainer will aim to acknowledge valid reports within seven days. Confirmed vulnerabilities may result in a patch, a new release, documentation changes, or other mitigation.

Reports that cannot be reproduced or that do not represent a security vulnerability may be closed with an explanation.

## Scope

Examples of issues that may qualify as security vulnerabilities include:

* Unauthorized deletion of attachments
* Missing or bypassable capability checks
* Cross-site request forgery
* Cross-site scripting
* SQL injection
* Exposure of sensitive WordPress or server information
* Actions that can be performed without the required administrator permissions

False positives, incomplete orphan detection, compatibility problems, feature requests, and general bugs should be reported through the repository's public **Issues** section.
