#!/usr/bin/env bash
#
# auto-push.sh — commit & push otomatis setiap ada perubahan file.
#
# Dipakai oleh cron/watch supaya "setiap penambahan atau edit file" langsung
# muncul di GitHub. Aman dipanggil sesering apa pun: kalau tidak ada perubahan,
# script keluar tanpa melakukan apa-apa (dan tanpa output).
#
# Pakai:
#   ./scripts/auto-push.sh                 # di repo Satuinaja
#   ./scripts/auto-push.sh /path/ke/repo   # repo lain
#
set -euo pipefail

REPO="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
cd "$REPO"

# Bukan repo git? keluar diam-diam.
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || exit 0

# Jangan pernah commit file yang tidak boleh publik, walau terlanjur ada.
for forbidden in backend/.env .env; do
  if [ -f "$forbidden" ] && git check-ignore -q "$forbidden" 2>/dev/null; then
    :
  fi
done

# Tidak ada perubahan? selesai.
if [ -z "$(git status --porcelain)" ]; then
  exit 0
fi

BRANCH="$(git rev-parse --abbrev-ref HEAD)"

# Susun pesan commit ringkas dari file yang berubah.
CHANGED="$(git status --porcelain | awk '{print $2}' | head -8 | tr '\n' ' ')"
COUNT="$(git status --porcelain | wc -l | tr -d ' ')"
MSG="chore(auto): ${COUNT} file diperbarui — ${CHANGED}"

git add -A
# Jalankan pre-commit kalau ada (mis. cek .env ikut ke-stage).
if git diff --cached --name-only | grep -qE '(^|/)\.env$'; then
  git reset HEAD . >/dev/null 2>&1 || true
  echo "DIBATALKAN: .env terdeteksi di stage. Cek .gitignore." >&2
  exit 1
fi

git commit -q -m "$MSG"
git push -q origin "$BRANCH"

echo "[auto-push] ${BRANCH}: ${COUNT} file -> GitHub"
