<?php

declare(strict_types=1);

namespace App\Helm;

class HelmChartGenerator
{
    private const DEFAULT_REPLICAS = 3;
    private const DEFAULT_PORT = 8080;

    private HelmConfig $config;
    private array $values = [];

    public function __construct(HelmConfig $config)
    {
        $this->config = $config;
        $this->initializeDefaults();
    }

    private function initializeDefaults(): void
    {
        $this->values = [
            'replicaCount' => self::DEFAULT_REPLICAS,
            'image' => [
                'repository' => $this->config->getImageRepository(),
                'pullPolicy' => 'IfNotPresent',
                'tag' => 'latest'
            ],
            'service' => [
                'type' => 'ClusterIP',
                'port' => self::DEFAULT_PORT
            ],
            'ingress' => [
                'enabled' => false,
                'className' => 'nginx',
                'annotations' => [],
                'hosts' => []
            ],
            'resources' => [
                'limits' => [
                    'cpu' => '500m',
                    'memory' => '512Mi'
                ],
                'requests' => [
                    'cpu' => '100m',
                    'memory' => '128Mi'
                ]
            ],
            'autoscaling' => [
                'enabled' => false,
                'minReplicas' => 1,
                'maxReplicas' => 10,
                'targetCPUUtilizationPercentage' => 70,
                'targetMemoryUtilizationPercentage' => 80
            ],
            'livenessProbe' => [
                'enabled' => true,
                'path' => '/health/live',
                'initialDelaySeconds' => 30,
                'periodSeconds' => 10,
                'timeoutSeconds' => 5,
                'failureThreshold' => 3
            ],
            'readinessProbe' => [
                'enabled' => true,
                'path' => '/health/ready',
                'initialDelaySeconds' => 10,
                'periodSeconds' => 5,
                'timeoutSeconds' => 3,
                'failureThreshold' => 3
            ],
            'env' => [],
            'secrets' => [],
            'configMaps' => []
        ];
    }

    public function setReplicas(int $count): self
    {
        $this->values['replicaCount'] = $count;

        return $this;
    }

    public function setImage(string $repository, string $tag = 'latest'): self
    {
        $this->values['image']['repository'] = $repository;
        $this->values['image']['tag'] = $tag;

        return $this;
    }

    public function setImagePullSecrets(string $secretName): self
    {
        $this->values['imagePullSecrets'] = [['name' => $secretName]];

        return $this;
    }

    public function addEnvironmentVariable(string $name, string $value): self
    {
        $this->values['env'][] = [
            'name' => $name,
            'value' => $value
        ];

        return $this;
    }

    public function addEnvironmentSecret(string $name, string $secretName, string $key): self
    {
        $this->values['env'][] = [
            'name' => $name,
            'valueFrom' => [
                'secretKeyRef' => [
                    'name' => $secretName,
                    'key' => $key
                ]
            ]
        ];

        return $this;
    }

    public function addEnvironmentConfigMap(string $name, string $configMapName, string $key): self
    {
        $this->values['env'][] = [
            'name' => $name,
            'valueFrom' => [
                'configMapKeyRef' => [
                    'name' => $configMapName,
                    'key' => $key
                ]
            ]
        ];

        return $this;
    }

    public function setServicePort(int $port): self
    {
        $this->values['service']['port'] = $port;

        return $this;
    }

    public function setServiceType(string $type): self
    {
        $this->values['service']['type'] = $type;

        return $this;
    }

    public function enableIngress(string $host, array $annotations = []): self
    {
        $this->values['ingress']['enabled'] = true;
        $this->values['ingress']['hosts'] = [
            [
                'host' => $host,
                'paths' => [
                    [
                        'path' => '/',
                        'pathType' => 'Prefix'
                    ]
                ]
            ]
        ];

        if (!empty($annotations)) {
            $this->values['ingress']['annotations'] = $annotations;
        }

        return $this;
    }

    public function setResources(array $requests, array $limits): self
    {
        $this->values['resources'] = [
            'requests' => $requests,
            'limits' => $limits
        ];

        return $this;
    }

    public function setLivenessProbe(string $path, int $initialDelay = 30): self
    {
        $this->values['livenessProbe'] = array_merge($this->values['livenessProbe'], [
            'path' => $path,
            'initialDelaySeconds' => $initialDelay
        ]);

        return $this;
    }

    public function setReadinessProbe(string $path, int $initialDelay = 10): self
    {
        $this->values['readinessProbe'] = array_merge($this->values['readinessProbe'], [
            'path' => $path,
            'initialDelaySeconds' => $initialDelay
        ]);

        return $this;
    }

    public function enableAutoscaling(int $minReplicas, int $maxReplicas): self
    {
        $this->values['autoscaling']['enabled'] = true;
        $this->values['autoscaling']['minReplicas'] = $minReplicas;
        $this->values['autoscaling']['maxReplicas'] = $maxReplicas;

        return $this;
    }

    public function addSecret(string $name, array $data): self
    {
        $this->values['secrets'][] = [
            'name' => $name,
            'data' => $data
        ];

        return $this;
    }

    public function addConfigMap(string $name, array $data): self
    {
        $this->values['configMaps'][] = [
            'name' => $name,
            'data' => $data
        ];

        return $this;
    }

    public function addVolume(string $name, string $mountPath, string $type = 'emptyDir', array $spec = []): self
    {
        $volume = [
            'name' => $name,
            'mountPath' => $mountPath
        ];

        if ($type === 'hostPath') {
            $volume['hostPath'] = $spec['path'] ?? '';
        } elseif ($type === 'persistentVolumeClaim') {
            $volume['persistentVolumeClaim']['claimName'] = $spec['claimName'] ?? '';
        }

        $this->values['volumes'][] = $volume;

        return $this;
    }

    public function addInitContainer(string $name, string $image, array $command = []): self
    {
        $container = [
            'name' => $name,
            'image' => $image
        ];

        if (!empty($command)) {
            $container['command'] = $command;
        }

        $this->values['initContainers'][] = $container;

        return $this;
    }

    public function setNodeSelector(array $selectors): self
    {
        $this->values['nodeSelector'] = $selectors;

        return $this;
    }

    public function addToleration(string $key, string $operator = 'Equal', string $value = '', string $effect = 'NoSchedule'): self
    {
        $this->values['tolerations'][] = [
            'key' => $key,
            'operator' => $operator,
            'value' => $value,
            'effect' => $effect
        ];

        return $this;
    }

    public function addAffinity(array $affinity): self
    {
        $this->values['affinity'] = $affinity;

        return $this;
    }

    public function setPriorityClass(string $priorityClass): self
    {
        $this->values['priorityClassName'] = $priorityClass;

        return $this;
    }

    public function setTerminationGracePeriod(int $seconds): self
    {
        $this->values['terminationGracePeriodSeconds'] = $seconds;

        return $this;
    }

    public function addAnnotation(string $key, string $value): self
    {
        $this->values['podAnnotations'][$key] = $value;

        return $this;
    }

    public function setSecurityContext(int $runAsUser = 1000, int $fsGroup = 1000): self
    {
        $this->values['podSecurityContext'] = [
            'runAsUser' => $runAsUser,
            'fsGroup' => $fsGroup
        ];

        $this->values['containerSecurityContext'] = [
            'runAsUser' => $runAsUser,
            'allowPrivilegeEscalation' => false
        ];

        return $this;
    }

    public function build(): array
    {
        $chart = [
            'apiVersion' => 'v2',
            'name' => $this->config->getChartName(),
            'version' => $this->config->getChartVersion(),
            'appVersion' => $this->values['image']['tag'],
            'description' => $this->config->getDescription()
        ];

        return [
            'chart' => $chart,
            'values' => $this->values
        ];
    }

    public function save(string $directory): void
    {
        $chartYaml = $this->arrayToYaml($this->build()['chart']);
        $valuesYaml = $this->arrayToYaml($this->values);

        $this->ensureDirectory("{$directory}/templates");

        file_put_contents("{$directory}/Chart.yaml", $chartYaml);
        file_put_contents("{$directory}/values.yaml", $valuesYaml);

        $this->generateTemplates($directory);
    }

    private function generateTemplates(string $directory): void
    {
        $deploymentTemplate = $this->buildDeploymentTemplate();
        $serviceTemplate = $this->buildServiceTemplate();
        $ingressTemplate = $this->buildIngressTemplate();

        file_put_contents("{$directory}/templates/deployment.yaml", $deploymentTemplate);
        file_put_contents("{$directory}/templates/service.yaml", $serviceTemplate);

        if ($this->values['ingress']['enabled']) {
            file_put_contents("{$directory}/templates/ingress.yaml", $ingressTemplate);
        }
    }

    private function buildDeploymentTemplate(): string
    {
        $template = <<<'YAML'
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ include "common.names.fullname" . }}
  labels:
    {{- include "common.labels" . | nindent 4 }}
spec:
  replicas: {{ .Values.replicaCount }}
  selector:
    matchLabels:
      {{- include "common.selector.labels" . | nindent 6 }}
  template:
    metadata:
      annotations:
        checksum/config: {{ include (print $.Template.BasePath "/configmap.yaml") . | sha256sum }}
      labels:
        {{- include "common.selector.labels" . | nindent 8 }}
    spec:
      {{- if .Values.imagePullSecrets }}
      imagePullSecrets:
        {{- range .Values.imagePullSecrets }}
        - name: {{ .name }}
        {{- end }}
      {{- end }}
      containers:
        - name: {{ .Chart.Name }}
          image: "{{ .Values.image.repository }}:{{ .Values.image.tag }}"
          imagePullPolicy: {{ .Values.image.pullPolicy }}
          ports:
            - name: http
              containerPort: {{ .Values.service.port }}
              protocol: TCP
          livenessProbe:
            httpGet:
              path: {{ .Values.livenessProbe.path }}
              port: http
            initialDelaySeconds: {{ .Values.livenessProbe.initialDelaySeconds }}
            periodSeconds: {{ .Values.livenessProbe.periodSeconds }}
          readinessProbe:
            httpGet:
              path: {{ .Values.readinessProbe.path }}
              port: http
            initialDelaySeconds: {{ .Values.readinessProbe.initialDelaySeconds }}
            periodSeconds: {{ .Values.readinessProbe.periodSeconds }}
          resources:
            {{- toYaml .Values.resources | nindent 12 }}
          env:
            {{- range .Values.env }}
            - name: {{ .name }}
              {{- if .value }}
              value: {{ .value }}
              {{- else if .valueFrom }}
              {{- toYaml .valueFrom | nindent 14 }}
              {{- end }}
            {{- end }}
YAML;

        return $template;
    }

    private function buildServiceTemplate(): string
    {
        return <<<'YAML'
apiVersion: v1
kind: Service
metadata:
  name: {{ include "common.names.fullname" . }}
  labels:
    {{- include "common.labels" . | nindent 4 }}
spec:
  type: {{ .Values.service.type }}
  ports:
    - port: {{ .Values.service.port }}
      targetPort: http
      protocol: TCP
      name: http
  selector:
    {{- include "common.selector.labels" . | nindent 4 }}
YAML;
    }

    private function buildIngressTemplate(): string
    {
        return <<<'YAML'
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ include "common.names.fullname" . }}
  labels:
    {{- include "common.labels" . | nindent 4 }}
  {{- with .Values.ingress.annotations }}
  annotations:
    {{- toYaml . | nindent 4 }}
  {{- end }}
spec:
  ingressClassName: {{ .Values.ingress.className }}
  rules:
    {{- range .Values.ingress.hosts }}
    - host: {{ .host }}
      http:
        paths:
          {{- range .paths }}
          - path: {{ .path }}
            pathType: {{ .pathType }}
            backend:
              service:
                name: {{ include "common.names.fullname" $ }}
                port:
                  number: {{ $.Values.service.port }}
          {{- end }}
    {{- end }}
YAML;
    }

    private function arrayToYaml(array $data): string
    {
        return \Symfony\Component\Yaml\Yaml::dump($data, 4, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL);
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
