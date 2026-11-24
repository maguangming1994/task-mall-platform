FROM node:24-alpine AS assets
WORKDIR /workspace
ENV VITE_BASE_PATH=/admin/
ARG VITE_API_BASE_URL=/api/v1
ARG VITE_H5_PUBLIC_URL
ARG VITE_WECHAT_OA_APP_ID
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT
ARG VITE_REVERB_SCHEME
ENV VITE_API_BASE_URL=${VITE_API_BASE_URL}
ENV VITE_H5_PUBLIC_URL=${VITE_H5_PUBLIC_URL}
ENV VITE_WECHAT_OA_APP_ID=${VITE_WECHAT_OA_APP_ID}
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}
ENV VITE_REVERB_HOST=${VITE_REVERB_HOST}
ENV VITE_REVERB_PORT=${VITE_REVERB_PORT}
ENV VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}
RUN corepack enable && corepack prepare pnpm@11.9.0 --activate
COPY .npmrc package.json pnpm-lock.yaml pnpm-workspace.yaml ./
COPY apps/user-app/package.json apps/user-app/package.json
COPY apps/admin-web/package.json apps/admin-web/package.json
RUN --mount=type=cache,id=task-mall-production-pnpm-v11,target=/pnpm/store,sharing=locked \
    pnpm install --frozen-lockfile --prefer-offline --store-dir=/pnpm/store --network-concurrency=4
COPY apps/user-app apps/user-app
COPY apps/admin-web apps/admin-web
RUN pnpm run build:user:h5 && pnpm run build:admin

FROM nginx:1.27-alpine
ARG RELEASE_VERSION=unknown
COPY --from=assets /workspace/apps/user-app/dist/build/h5/ /usr/share/nginx/html/
COPY --from=assets /workspace/apps/admin-web/dist/ /usr/share/nginx/admin/
# 平台内容素材（任务封面/头像/banner）由 API 随仓库发布，经同域 /content/ 提供给 H5 与小程序。
COPY apps/api/public/content/ /usr/share/nginx/html/content/
COPY infra/nginx/task-mall.conf /etc/nginx/conf.d/default.conf
RUN printf '{"release":"%s"}\n' "$RELEASE_VERSION" > /usr/share/nginx/html/release.json
EXPOSE 8080
