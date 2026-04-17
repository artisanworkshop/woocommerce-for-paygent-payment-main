#!/usr/bin/env bash
# Check if 2025docs PDF files have been updated since skills were last reviewed.
# Usage: ./scripts/check-pdf-updates.sh

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
DOCS_DIR="${REPO_ROOT}/2025docs"
HASH_FILE="${REPO_ROOT}/.claude/pdf-hashes.txt"

CHANGED_FILES=""
AFFECTED_SKILLS=""

check_pdf() {
  local relative_path="$1"
  local skills="$2"
  local full_path="${DOCS_DIR}/${relative_path}"

  if [[ ! -f "${full_path}" ]]; then
    echo "WARNING: PDF not found: ${relative_path}"
    return
  fi

  local current_hash
  current_hash=$(md5 -q "${full_path}" 2>/dev/null || md5sum "${full_path}" | awk '{print $1}')
  local stored_hash=""
  if [[ -f "${HASH_FILE}" ]]; then
    stored_hash=$(grep -F "${relative_path}" "${HASH_FILE}" | awk '{print $1}' || true)
  fi

  if [[ "${current_hash}" != "${stored_hash}" ]]; then
    CHANGED_FILES="${CHANGED_FILES}\n  - ${relative_path}"
    for skill in $skills; do
      if ! echo "${AFFECTED_SKILLS}" | grep -q "${skill}"; then
        AFFECTED_SKILLS="${AFFECTED_SKILLS}\n  - ${skill} → .claude/skills/${skill}/"
      fi
    done
  fi
}

check_pdf "system/モジュールタイプ/02_PG外部インターフェース仕様説明書.pdf" "paygent-core paygent-cc paygent-bank"
check_pdf "system/モジュールタイプ/02_PG外部インターフェース仕様説明書（トークン決済）.pdf" "paygent-cc"
check_pdf "PayPay/02_PG外部インターフェース仕様説明書（別紙：PayPay）.pdf" "paygent-digital"
check_pdf "Paidy/02_PG外部インターフェース仕様説明書（別紙：Paidy）.pdf" "paygent-digital"
check_pdf "楽天ペイ/02_PG外部インターフェース仕様説明書（別紙：楽天ペイ）.pdf" "paygent-digital"
check_pdf "ApplePay/02_PG外部インターフェース仕様説明書（別紙：Apple Pay）.pdf" "paygent-digital"
check_pdf "GooglePay/02_PG外部インターフェース仕様説明書（別紙：Google Pay）.pdf" "paygent-digital"
check_pdf "Alipay国際決済/02_PG外部インターフェース仕様説明書（別紙：Alipay国際決済）.pdf" "paygent-digital"
check_pdf "銀聯ネット決済/02_PG外部インターフェース仕様説明書（別紙：銀聯ネット決済）.pdf" "paygent-digital"
check_pdf "携帯キャリア決済（都度課金）/02_PG外部インターフェース仕様説明書（別紙：携帯キャリア決済）.pdf" "paygent-carrier"
check_pdf "携帯キャリア決済（継続課金）/02_PG外部インターフェース仕様説明書（別紙：携帯キャリア決済継続課金）.pdf" "paygent-carrier"

if [[ -z "${CHANGED_FILES}" ]]; then
  echo "All PDF files are up to date. No skill review needed."
  exit 0
fi

echo "======================================"
echo "PDF FILES UPDATED - SKILL REVIEW NEEDED"
echo "======================================"
echo ""
echo "Changed PDFs:"
echo -e "${CHANGED_FILES}"
echo ""
echo "Skills requiring review:"
echo -e "${AFFECTED_SKILLS}"
echo ""
echo "Next steps:"
echo "  1. Read the updated PDFs: pdftotext \"2025docs/<path>\" - | less"
echo "  2. Compare changes against .claude/skills/<skill>/references/"
echo "  3. Update affected skill files with any spec changes"
echo "  4. Run: ./scripts/update-pdf-hashes.sh  to record new hashes"
echo ""

exit 1
