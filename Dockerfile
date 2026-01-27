FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# pré-requisitos mínimos para PPA
RUN apt-get update && apt-get install -y \
    software-properties-common \
    ca-certificates \
    curl \
    gnupg \
    git \
    unzip

# adicionar PPA do Ondřej Surý (Ubuntu)
RUN add-apt-repository ppa:ondrej/php -y

# instalar PHP 8.4
RUN apt-get update && apt-get install -y \
    php8.4-dev \
    php8.4-cli \
    php8.4-xhprof \
    php8.4-opcache

# extensão VLD para debug de opcodes (assembly)
RUN pecl install channel://pecl.php.net/vld-0.19.1
RUN echo "extension=vld.so" > /etc/php/8.4/cli/conf.d/20-vld.ini

WORKDIR /app