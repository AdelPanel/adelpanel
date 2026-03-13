#!/bin/bash
# disable_swap.sh
set -euo pipefail
swapoff -a
sed -i '/swapfile\|swap/d' /etc/fstab
rm -f /swapfile
echo "OK: swap disabled"
