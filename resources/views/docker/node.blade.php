FROM node:22-alpine AS node

WORKDIR /usr/src/app

COPY package*.json ./
COPY . .

RUN npm install

FROM node AS production

RUN npm run build
