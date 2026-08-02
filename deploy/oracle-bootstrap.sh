#!/usr/bin/env bash
set -euo pipefail

# Small Always Free instances have 1 GB of RAM. Oracle can provide a small
# default swap file, so add our own when the total swap is still below 2 GB.
if [ "$(free -m | awk '/^Swap:/ {print $2}')" -lt 1900 ]; then
  if [ ! -e /swapfile ]; then
    sudo fallocate -l 2G /swapfile
    sudo chmod 600 /swapfile
    sudo mkswap /swapfile
  fi
  if ! swapon --show=NAME --noheadings | grep -qx '/swapfile'; then
    sudo swapon /swapfile
  fi
  if ! grep -q '^/swapfile ' /etc/fstab; then
    echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab >/dev/null
  fi
fi

if command -v dnf >/dev/null 2>&1; then
  # Oracle Linux 9 is compatible with the official Docker RHEL repository.
  sudo dnf -y remove docker docker-client docker-client-latest docker-common docker-latest docker-latest-logrotate docker-logrotate docker-engine podman runc || true
  sudo dnf -y install dnf-plugins-core ca-certificates curl
  sudo dnf config-manager --add-repo https://download.docker.com/linux/rhel/docker-ce.repo
  sudo dnf -y install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
elif command -v apt-get >/dev/null 2>&1; then
  sudo apt-get update
  sudo apt-get install -y ca-certificates curl
  sudo install -m 0755 -d /etc/apt/keyrings
  sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  sudo chmod a+r /etc/apt/keyrings/docker.asc
  . /etc/os-release
  ARCH="$(dpkg --print-architecture)"
  CODENAME="${UBUNTU_CODENAME:-$VERSION_CODENAME}"
  echo "deb [arch=$ARCH signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $CODENAME stable" | sudo tee /etc/apt/sources.list.d/docker.list >/dev/null
  sudo apt-get update
  sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
else
  echo "Unsupported operating system: dnf or apt-get is required." >&2
  exit 1
fi

sudo systemctl enable --now docker
sudo usermod -aG docker "$USER"

echo "Docker installed. Close SSH and connect again before using docker without sudo."
