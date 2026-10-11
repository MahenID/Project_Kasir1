#!/usr/bin/env bash
#
# backup-restore.sh — prosedur DR DragonMart POS (NFR-REL-02, PRD §10).
#
# RPO target ≤24 jam  : jalankan `backup` tiap ≤24 jam (cron mis. 0 2 * * *).
# RTO target ≤4 jam   : `restore` ke DB bersih, terukur ~beberapa detik untuk
#                       dataset kecil; tetap jauh di bawah 4 jam pada volume
#                       produksi karena restore adalah I/O berurutan.
#
# Pemakaian:
#   ./backup-restore.sh backup  [db] [outfile]
#   ./backup-restore.sh restore <dumpfile> [target_db]
#   ./backup-restore.sh verify  <source_db> [target_db]
#
# Container default: kasir-mysql. Kredensial dapat dioverride via env:
#   MYSQL_CONTAINER, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DB
set -euo pipefail

CONTAINER="${MYSQL_CONTAINER:-kasir-mysql}"
DB_USER="${MYSQL_USER:-kasir_user}"
DB_PASS="${MYSQL_PASSWORD:-kasir_password}"
DB_NAME="${MYSQL_DB:-kasir_db}"

cmd_backup() {
  local db="${1:-$DB_NAME}"
  local out="${2:-backup_${db}_$(date +%Y%m%d_%H%M%S).sql}"
  echo "[backup] DB=$db -> $out"
  podman exec "$CONTAINER" \
    mysqldump -u "$DB_USER" -p"$DB_PASS" \
      --single-transaction --quick --routines --triggers "$db" \
    > "$out"
  echo "[backup] selesai: $(wc -c < "$out") bytes"
}

cmd_restore() {
  local dump="${1:?dumpfile wajib}"
  local target="${2:-${DB_NAME}_restore}"
  echo "[restore] $dump -> DB=$target"
  podman exec "$CONTAINER" mysql -u root -p"${MYSQL_ROOT_PASSWORD:-kasir_root_password}" \
    -e "DROP DATABASE IF EXISTS \`$target\`;
        CREATE DATABASE \`$target\`;
        GRANT ALL PRIVILEGES ON \`$target\`.* TO '$DB_USER'@'%';
        FLUSH PRIVILEGES;"
  podman exec -i "$CONTAINER" mysql -u "$DB_USER" -p"$DB_PASS" "$target" < "$dump"
  echo "[restore] selesai"
}

# Bandingkan jumlah baris eksak tabel kunci antara DB asal dan hasil restore.
cmd_verify() {
  local src="${1:-$DB_NAME}"
  local dst="${2:-${DB_NAME}_restore}"
  local tables=(users products sales sale_items payments shifts
                return_requests return_items refunds receivings
                operation_keys inventory_control)
  local mismatch=0
  printf "%-22s %-10s %-10s %s\n" "TABLE" "SOURCE" "RESTORE" "STATUS"
  for t in "${tables[@]}"; do
    local a b
    a=$(podman exec "$CONTAINER" mysql -u "$DB_USER" -p"$DB_PASS" -D "$src" -N -e "SELECT COUNT(*) FROM $t;" 2>/dev/null)
    b=$(podman exec "$CONTAINER" mysql -u "$DB_USER" -p"$DB_PASS" -D "$dst" -N -e "SELECT COUNT(*) FROM $t;" 2>/dev/null)
    if [ "$a" = "$b" ]; then
      printf "%-22s %-10s %-10s MATCH\n" "$t" "$a" "$b"
    else
      printf "%-22s %-10s %-10s MISMATCH\n" "$t" "$a" "$b"
      mismatch=$((mismatch + 1))
    fi
  done
  if [ "$mismatch" -eq 0 ]; then
    echo "[verify] OK: semua tabel kunci konsisten"
  else
    echo "[verify] GAGAL: $mismatch tabel tidak konsisten" >&2
    exit 1
  fi
}

case "${1:-}" in
  backup)  shift; cmd_backup "$@" ;;
  restore) shift; cmd_restore "$@" ;;
  verify)  shift; cmd_verify "$@" ;;
  *) echo "Pemakaian: $0 {backup|restore|verify} ..." >&2; exit 2 ;;
esac
