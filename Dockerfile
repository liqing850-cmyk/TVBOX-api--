# 使用官方PHP 8.2 Apache镜像（支持你的PHP版本）
FROM php:8.2-apache

# 安装必要扩展：PDO SQLite, cURL, Intl (for IDNA)
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libcurl4-openssl-dev \
    libicu-dev \
    && docker-php-ext-install pdo_sqlite intl \
    && docker-php-ext-enable pdo_sqlite intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 启用Apache mod_rewrite（如果需要URL重写）
RUN a2enmod rewrite

# 设置工作目录
WORKDIR /var/www/html

# 复制项目文件
COPY index.php .
COPY data/ ./data/
COPY json/ ./json/
COPY sessions/ ./sessions/

# 设置目录权限（确保PHP可写data/json/sessions）
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/data /var/www/html/json /var/www/html/sessions

# 暴露端口80
EXPOSE 80

# 启动Apache
CMD ["apache2-foreground"]