# Imagem para rodar a matriz localmente com o mesmo Ubuntu do runner do
# GitHub: PHP 8.3 com as extensões do Flarum e ferramentas dos scripts. O MTA
# (Exim ou Postfix) é instalado pela própria célula, como no CI.
FROM ubuntu:24.04
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends \
      php8.3-cli php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-curl php8.3-intl \
      php8.3-gd php8.3-zip php8.3-redis php8.3-mysql php8.3-iconv \
      curl jq ca-certificates openssl procps redis-server rsyslog git unzip \
      debconf-utils libstdc++6 \
    && rm -rf /var/lib/apt/lists/*
