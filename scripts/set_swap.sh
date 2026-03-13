#!/bin/bash
# set_swap.sh
set -euo pipefail
SIZE_MB="$1"
if [[ ! "$SIZE_MB" =~ ^(512|1024|2048|4096)$ ]]; then echo "ERROR: invalid swap size"; exit 1; fi

# Отключаем старый своп
swapoff -a 2>/dev/null || true
sed -i '/swapfile/d' /etc/fstab

# Создаём новый
fallocate -l "${SIZE_MB}M" /swapfile || dd if=/dev/zero of=/swapfile bs=1M count="$SIZE_MB" 2>/dev/null
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
echo "OK: swap ${SIZE_MB}MB enabled"
