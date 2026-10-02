#!/usr/bin/env python3
"""Pull objective completion counts from a customer-job evidence manifest.

[MANIFEST_PATH]
Example: evidence/job-evidence.json

Use ``-`` as the manifest path to read JSON from standard input. The script
uses only the Python standard library and never reads secret values from the
manifest beyond counting the supplied records.
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime
from pathlib import Path
from typing import Any


def list_value(data: dict[str, Any], key: str) -> list[Any]:
    value = data.get(key, [])
    if not isinstance(value, list):
        raise ValueError(f"{key} must be a JSON array")
    return value


def parse_time(value: str | None) -> datetime | None:
    if value is None:
        return None
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def load_manifest(path: str) -> dict[str, Any]:
    if path == "-":
        value = json.load(sys.stdin)
    else:
        with Path(path).open(encoding="utf-8") as handle:
            value = json.load(handle)

    if not isinstance(value, dict):
        raise ValueError("manifest root must be a JSON object")
    return value


def calculate(data: dict[str, Any]) -> dict[str, Any]:
    request_sources = list_value(data, "request_sources")
    system_records = list_value(data, "system_records")
    requirements = list_value(data, "requirements")
    automated_checks = list_value(data, "automated_checks")
    weekly_reports = list_value(data, "weekly_reports")

    unlinked_records = 0
    late_without_reason = 0
    for record in system_records:
        if not isinstance(record, dict):
            raise ValueError("every system_records item must be an object")
        if not record.get("source_refs"):
            unlinked_records += 1
        received_at = parse_time(record.get("received_at"))
        registered_at = parse_time(record.get("registered_at"))
        if (
            received_at is not None
            and registered_at is not None
            and received_at.date() != registered_at.date()
            and not record.get("delay_reason")
        ):
            late_without_reason += 1

    implemented = 0
    approved_exclusions = 0
    requirements_without_evidence = 0
    for requirement in requirements:
        if not isinstance(requirement, dict):
            raise ValueError("every requirements item must be an object")
        status = requirement.get("status")
        if status == "implemented":
            implemented += 1
        elif status == "approved_exclusion":
            approved_exclusions += 1
        if not requirement.get("evidence_refs"):
            requirements_without_evidence += 1

    requirement_total = len(requirements)
    coverage = (
        (implemented + approved_exclusions) / requirement_total
        if requirement_total
        else None
    )

    failed_checks = 0
    for check in automated_checks:
        if not isinstance(check, dict):
            raise ValueError("every automated_checks item must be an object")
        if check.get("exit_code") != 0:
            failed_checks += 1

    artifacts = data.get("artifacts", {})
    if not isinstance(artifacts, dict):
        raise ValueError("artifacts must be a JSON object")
    artifact_counts = {}
    for name, values in artifacts.items():
        if not isinstance(values, list):
            raise ValueError(f"artifacts.{name} must be a JSON array")
        artifact_counts[name] = len(values)

    started_at = parse_time(data.get("started_at"))
    completed_at = parse_time(data.get("completed_at"))
    duration_days = None
    expected_weekly_reports = None
    if started_at is not None and completed_at is not None:
        duration_days = (completed_at - started_at).days
        expected_weekly_reports = max(duration_days, 0) // 7

    return {
        "request_source_count": len(request_sources),
        "system_record_count": len(system_records),
        "unlinked_system_record_count": unlinked_records,
        "late_without_reason_count": late_without_reason,
        "requirement_total": requirement_total,
        "implemented_count": implemented,
        "approved_exclusion_count": approved_exclusions,
        "requirement_coverage": round(coverage, 4) if coverage is not None else None,
        "requirements_without_evidence_count": requirements_without_evidence,
        "automated_check_count": len(automated_checks),
        "failed_automated_check_count": failed_checks,
        "artifact_counts": artifact_counts,
        "duration_days": duration_days,
        "expected_weekly_report_count": expected_weekly_reports,
        "actual_weekly_report_count": len(weekly_reports),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("manifest", help="UTF-8 JSON manifest path, or - for stdin")
    parser.add_argument("--pretty", action="store_true", help="indent JSON output")
    args = parser.parse_args()

    try:
        result = calculate(load_manifest(args.manifest))
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print(f"error: {error}", file=sys.stderr)
        return 1

    print(json.dumps(result, ensure_ascii=False, indent=2 if args.pretty else None))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
