FROM node:24-alpine AS assets
WORKDIR /workspace
ENV VITE_BASE_PATH=/admin/
RUN corepack enable && corepack prepare pnpm@11.9.0 --activate
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
COPY apps/user-app/package.json apps/user-app/package.json
COPY apps/admin-web/package.json apps/admin-web/package.json
RUN pnpm install --frozen-lockfile
COPY apps/user-app apps/user-app
COPY apps/admin-web apps/admin-web
RUN pnpm run build:user:h5 && pnpm run build:admin

FROM nginx:1.27-alpine
COPY --from=assets /workspace/apps/user-app/dist/build/h5/ /usr/share/nginx/html/
COPY --from=assets /workspace/apps/admin-web/dist/ /usr/share/nginx/admin/
COPY infra/nginx/task-mall.conf /etc/nginx/conf.d/default.conf
EXPOSE 8080
