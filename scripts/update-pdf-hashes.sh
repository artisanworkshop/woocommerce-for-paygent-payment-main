#!/usr/bin/env bash
# Record current MD5 hashes of all 2025docs PDF spec files.
# Run this after reviewing and updating skills to mark them as current.
# Usage: ./scripts/update-pdf-hashes.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
DOCS_DIR="${REPO_ROOT}/2025docs"
HASH_FILE="${REPO_ROOT}/.claude/pdf-hashes.txt"

PDFS=(
  "system/モジュールタイプ/02_PG外部インターフェース仕様説明書.pdf"
  "system/モジュールタイプ/02_PG外部インターフェース仕様説明書（トークン決済）.pdf"
  "PayPay/02_PG外部インターフェース仕様説明書（別紙：PayPay）.pdf"
  "Paidy/02_PG外部インターフェース仕様説明書（別紙：Paidy）.pdf"
  "楽天ペイ/02_PG外部インターフェース仕様説明書（別紙：楽天ペイ）.pdf"
  "ApplePay/02_PG外部インターフェース仕様説明書（別紙：Apple Pay）.pdf"
  "GooglePay/02_PG外部インターフェース仕様説明書（別紙：Google Pay）.pdf"
  "Alipay国際決済/02_PG外部インターフェース仕様説明書（別紙：Alipay国際決済）.pdf"
  "銀聯ネット決済/02_PG外部インターフェース仕様説明書（別紙：銀聯ネット決済）.pdf"
  "携帯キャリア決済（都度課金）/02_PG外部インターフェース仕様説明書（別紙：携帯キャリア決済）.pdf"
  "携帯キャリア決済（継続課金）/02_PG外部インターフェース仕様説明書（別紙：携帯キャリア決済継続課金）.pdf"
)

> "${HASH_FILE}"

for relative_path in "${PDFS[@]}"; do
  full_path="${DOCS_DIR}/${relative_path}"
  if [[ ! -f "${full_path}" ]]; then
    echo "WARNING: PDF not found, skipping: ${relative_path}"
    continue
  fi
  hash=$(md5 -q "${full_path}" 2>/dev/null || md5sum "${full_path}" | awk '{print $1}')
  echo "${hash}  ${relative_path}" >> "${HASH_FILE}"
  echo "Recorded: ${relative_path}"
done

echo ""
echo "Hashes saved to: ${HASH_FILE}"
echo "Run ./scripts/check-pdf-updates.sh to verify."
