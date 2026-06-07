{{-- Same per-pod emptyDir swap as storage-emptydir, but for the scheduler
     CronJob's deeper pod-spec path. Targeted by name (CronJob: scheduler) in the
     overlay kustomization. --}}
- op: test
  path: /spec/jobTemplate/spec/template/spec/volumes/0/name
  value: storage
- op: replace
  path: /spec/jobTemplate/spec/template/spec/volumes/0
  value:
    name: storage
    emptyDir: {}
