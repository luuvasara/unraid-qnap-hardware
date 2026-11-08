#!/bin/bash
# serve.sh [theme] [port] - the built package on the made-up server of mkserver.sh, served by the render harness until stopped
# (Ctrl+C), for looking at the pages in a browser and for the axe check. Workstation only.
set -uo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd); NAME=qnap-hardware
# shellcheck disable=SC1091
. "$HERE/kit/render/render-lib.sh"
PLUGIN_ROOT=$HERE "$HERE/kit/render/fetch-webgui.sh" >/dev/null
theme=${1:-black}; port=${2:-$(free_port)}
K=$HERE/tests/.work/server-serve-$theme; bash "$HERE/tests/mkserver.sh" "$K"
export DRIVEMAP_ROOT=$K QNAPHW_SYS=$K/sys QNAPHW_PROC=$K/proc QNAPHW_ETC=$K/etc QNAPHW_KERNEL=6.18.58-Unraid QNAPHW_BIN_LSPCI=$K/bin/lspci QNAPHW_BIN_LSUSB=$K/bin/lsusb QNAPHW_BIN_DMESG=$K/bin/dmesg
web=$HERE/tests/.work/serve-$theme; stage_web "$web" || exit 1
start_harness "$web" "$port" "$theme" "Overview=QnapHWOverview,History=QnapHWHistory,Hardware=QnapHWHardware,Report=QnapHWReport,About=QnapHWAbout"
echo "http://127.0.0.1:$port/?tab=1"
wait
