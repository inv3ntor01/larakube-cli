apiVersion: apps/v1
kind: Deployment
metadata:
  name: meilisearch
spec:
  template:
    spec:
      containers:
        - name: meilisearch
          env:
            - name: MEILI_ENV
              value: "development"
            - name: MEILI_NO_ANALYTICS
              value: "true"
