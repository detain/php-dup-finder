<?php

declare(strict_types=1);

namespace App\Deployment\Terraform;

class TerraformModuleGenerator
{
    private const DEFAULT_INSTANCE_TYPE = 't3.medium';
    private const DEFAULT_REGION = 'us-east-1';

    private TerraformConfig $config;
    private array $resources = [];
    private array $variables = [];
    private array $outputs = [];
    private array $dataSources = [];

    public function __construct(TerraformConfig $config)
    {
        $this->config = $config;
    }

    public function addVariable(string $name, string $type, string $description, $default = null): self
    {
        $var = [
            'type' => $type,
            'description' => $description
        ];

        if ($default !== null) {
            $var['default'] = $default;
        }

        $this->variables[$name] = $var;

        return $this;
    }

    public function addStringVariable(string $name, string $description, string $default = ''): self
    {
        return $this->addVariable($name, 'string', $description, $default);
    }

    public function addNumberVariable(string $name, string $description, $default = null): self
    {
        return $this->addVariable($name, 'number', $description, $default);
    }

    public function addBoolVariable(string $name, string $description, bool $default = false): self
    {
        return $this->addVariable($name, 'bool', $description, $default);
    }

    public function addListVariable(string $name, string $description, array $default = []): self
    {
        return $this->addVariable($name, 'list(string)', $description, $default);
    }

    public function addMapVariable(string $name, string $description, array $default = []): self
    {
        return $this->addVariable($name, 'map(string)', $description, $default);
    }

    public function addOutput(string $name, string $description, string $expression): self
    {
        $this->outputs[$name] = [
            'description' => $description,
            'value' => $expression
        ];

        return $this;
    }

    public function addVpc(string $cidr, string $namePrefix): self
    {
        $vpcId = 'aws_vpc.' . $namePrefix . '_vpc.id';

        $this->resources['aws_vpc'][] = [
            'name' => $namePrefix . '_vpc',
            'cidr_block' => $cidr,
            'enable_dns_hostnames' => true,
            'enable_dns_support' => true,
            'tags' => [
                'Name' => $namePrefix,
                'Environment' => '${var.environment}'
            ]
        ];

        $this->addInternetGateway($namePrefix, $vpcId);
        $this->addPublicSubnet($namePrefix, $cidr, $vpcId);
        $this->addPrivateSubnet($namePrefix, $cidr, $vpcId);
        $this->addNatGateway($namePrefix, $vpcId);
        $this->addRouteTable($namePrefix, $vpcId);

        return $this;
    }

    public function addInternetGateway(string $namePrefix, string $vpcId): self
    {
        $this->resources['aws_internet_gateway'][] = [
            'name' => $namePrefix . '_igw',
            'vpc_id' => $vpcId,
            'tags' => [
                'Name' => $namePrefix . '-igw',
                'Environment' => '${var.environment}'
            ]
        ];

        return $this;
    }

    public function addPublicSubnet(string $namePrefix, string $vpcCidr, string $vpcId): self
    {
        $subnetCidr = $this->calculateSubnetCidr($vpcCidr, 0);

        $this->resources['aws_subnet'][] = [
            'name' => $namePrefix . '_public_subnet',
            'vpc_id' => $vpcId,
            'cidr_block' => $subnetCidr,
            'availability_zone' => '${var.availability_zones[0]}',
            'map_public_ip_on_launch' => true,
            'tags' => [
                'Name' => $namePrefix . '-public',
                'Environment' => '${var.environment}',
                'Type' => 'public'
            ]
        ];

        $this->addRouteTableAssociation($namePrefix . '_public_subnet', '${aws_route_table.' . $namePrefix . '_public_rt.id}');

        return $this;
    }

    public function addPrivateSubnet(string $namePrefix, string $vpcCidr, string $vpcId): self
    {
        $subnetCidr = $this->calculateSubnetCidr($vpcCidr, 1);

        $this->resources['aws_subnet'][] = [
            'name' => $namePrefix . '_private_subnet',
            'vpc_id' => $vpcId,
            'cidr_block' => $subnetCidr,
            'availability_zone' => '${var.availability_zones[0]}',
            'map_public_ip_on_launch' => false,
            'tags' => [
                'Name' => $namePrefix . '-private',
                'Environment' => '${var.environment}',
                'Type' => 'private'
            ]
        ];

        return $this;
    }

    public function addNatGateway(string $namePrefix, string $vpcId): self
    {
        $eipName = $namePrefix . '_eip';
        $natName = $namePrefix . '_nat';

        $this->resources['aws_eip'][] = [
            'name' => $eipName,
            'vpc' => true,
            'depends_on' => ['aws_internet_gateway.' . $namePrefix . '_igw']
        ];

        $this->resources['aws_nat_gateway'][] = [
            'name' => $natName,
            'allocation_id' => '${aws_eip.' . $eipName . '.id}',
            'subnet_id' => '${aws_subnet.' . $namePrefix . '_public_subnet.id}',
            'depends_on' => ['aws_internet_gateway.' . $namePrefix . '_igw']
        ];

        return $this;
    }

    public function addRouteTable(string $namePrefix, string $vpcId): self
    {
        $this->resources['aws_route_table'][] = [
            'name' => $namePrefix . '_public_rt',
            'vpc_id' => $vpcId,
            'route' => [
                'cidr_block' => '0.0.0.0/0',
                'gateway_id' => '${aws_internet_gateway.' . $namePrefix . '_igw.id}'
            ],
            'tags' => [
                'Name' => $namePrefix . '-public-rt',
                'Environment' => '${var.environment}'
            ]
        ];

        return $this;
    }

    public function addRouteTableAssociation(string $subnetName, string $routeTableId): self
    {
        $this->resources['aws_route_table_association'][] = [
            'name' => $subnetName . '_association',
            'subnet_id' => '${aws_subnet.' . $subnetName . '.id}',
            'route_table_id' => $routeTableId
        ];

        return $this;
    }

    public function addSecurityGroup(string $namePrefix, string $vpcId, array $ingressRules, array $egressRules = []): self
    {
        $sgId = 'aws_security_group.' . $namePrefix . '_sg.id';

        $this->resources['aws_security_group'][] = [
            'name' => $namePrefix . '_sg',
            'vpc_id' => $vpcId,
            'description' => 'Security group for ' . $namePrefix,
            'ingress' => $ingressRules,
            'egress' => !empty($egressRules) ? $egressRules : [
                ['cidr_blocks' => ['0.0.0.0/0'], 'description' => 'Allow all outbound']
            ],
            'tags' => [
                'Name' => $namePrefix . '-sg',
                'Environment' => '${var.environment}'
            ]
        ];

        return $this;
    }

    public function addEc2Instance(string $namePrefix, string $ami, string $instanceType, string $subnetId, string $securityGroupId): self
    {
        $this->resources['aws_instance'][] = [
            'name' => $namePrefix . '_instance',
            'ami' => $ami,
            'instance_type' => $instanceType,
            'subnet_id' => $subnetId,
            'vpc_security_group_ids' => [$securityGroupId],
            'key_name' => '${var.ssh_key_name}',
            'user_data' => file_exists(getcwd() . '/scripts/init.sh')
                ? file_get_contents(getcwd() . '/scripts/init.sh')
                : null,
            'root_block_device' => [
                'volume_size' => 20,
                'volume_type' => 'gp3',
                'encrypted' => true
            ],
            'tags' => [
                'Name' => $namePrefix,
                'Environment' => '${var.environment}',
                'ManagedBy' => 'Terraform'
            ]
        ];

        return $this;
    }

    public function addAlb(string $namePrefix, string $vpcId, array $subnetIds): self
    {
        $this->resources['aws_lb'][] = [
            'name' => $namePrefix . '_alb',
            'internal' => false,
            'load_balancer_type' => 'application',
            'subnets' => $subnetIds,
            'security_groups' => ['${aws_security_group.' . $namePrefix . '_sg.id}'],
            'enable_deletion_protection' => false,
            'tags' => [
                'Name' => $namePrefix . '-alb',
                'Environment' => '${var.environment}'
            ]
        ];

        $this->resources['aws_lb_target_group'][] = [
            'name' => $namePrefix . '_tg',
            'port' => 80,
            'protocol' => 'HTTP',
            'vpc_id' => $vpcId,
            'health_check' => [
                'enabled' => true,
                'path' => '/health',
                'interval' => 30,
                'timeout' => 5,
                'healthy_threshold' => 2,
                'unhealthy_threshold' => 2
            ]
        ];

        $this->resources['aws_lb_listener'][] = [
            'name' => $namePrefix . '_listener',
            'load_balancer_arn' => '${aws_lb.' . $namePrefix . '_alb.arn}',
            'port' => 80,
            'protocol' => 'HTTP',
            'default_action' => [
                'type' => 'forward',
                'target_group_arn' => '${aws_lb_target_group.' . $namePrefix . '_tg.arn}'
            ]
        ];

        return $this;
    }

    public function addRdsInstance(
        string $namePrefix,
        string $vpcId,
        string $subnetIds,
        string $securityGroupId,
        string $databaseName,
        string $instanceClass = 'db.t3.micro'
    ): self {
        $this->resources['aws_db_subnet_group'][] = [
            'name' => $namePrefix . '_db_subnet',
            'subnet_ids' => $subnetIds,
            'tags' => ['Name' => $namePrefix . '-db-subnet']
        ];

        $this->resources['aws_db_instance'][] = [
            'name' => $namePrefix . '_db',
            'identifier' => $namePrefix . '-${var.environment}',
            'engine' => 'postgres',
            'engine_version' => '14.7',
            'instance_class' => $instanceClass,
            'allocated_storage' => 20,
            'max_allocated_storage' => 100,
            'storage_encrypted' => true,
            'db_name' => $databaseName,
            'username' => '${var.db_username}',
            'password' => '${var.db_password}',
            'vpc_security_group_ids' => [$securityGroupId],
            'db_subnet_group_name' => '${aws_db_subnet_group.' . $namePrefix . '_db_subnet.name}',
            'multi_az' => '${var.environment == "production" ? true : false}',
            'backup_retention_period' => '${var.environment == "production" ? 7 : 1}',
            'skip_final_snapshot' => '${var.environment != "production"}',
            'tags' => [
                'Name' => $namePrefix . '-db',
                'Environment' => '${var.environment}'
            ]
        ];

        return $this;
    }

    public function addElastiCacheRedis(
        string $namePrefix,
        string $vpcId,
        string $subnetIds,
        string $securityGroupId
    ): self {
        $this->resources['aws_elasticache_subnet_group'][] = [
            'name' => $namePrefix . '_cache_subnet',
            'subnet_ids' => $subnetIds
        ];

        $this->resources['aws_elasticache_cluster'][] = [
            'name' => $namePrefix . '_cache',
            'engine' => 'redis',
            'engine_version' => '7.0',
            'node_type' => 'cache.t3.micro',
            'num_cache_nodes' => 1,
            'parameter_group_name' => 'default.redis7',
            'port' => 6379,
            'security_group_ids' => [$securityGroupId],
            'subnet_group_name' => '${aws_elasticache_subnet_group.' . $namePrefix . '_cache_subnet.name}',
            'tags' => [
                'Name' => $namePrefix . '-cache',
                'Environment' => '${var.environment}'
            ]
        ];

        return $this;
    }

    private function calculateSubnetCidr(string $vpcCidr, int $subnetIndex): string
    {
        $parts = explode('.', $vpcCidr);
        $thirdOctet = ($subnetIndex * 4) + 16;

        return $parts[0] . '.' . $parts[1] . '.' . $thirdOctet . '.0/24';
    }

    public function addDataSource(string $type, string $name, array $config): self
    {
        $this->dataSources[$type][] = array_merge(['name' => $name], $config);

        return $this;
    }

    public function build(): array
    {
        $hcl = '';

        foreach ($this->variables as $name => $config) {
            $default = isset($config['default']) ? " = {$this->formatValue($config['default'])}" : '';
            $hcl .= "variable \"{$name}\" {\n  type        = {$config['type']}\n  description = \"{$config['description']}\"\n{$default}\n}\n\n";
        }

        foreach ($this->dataSources as $type => $dataItems) {
            foreach ($dataItems as $item) {
                $name = $item['name'];
                unset($item['name']);
                $hcl .= "data \"{$type}\" \"{$name}\" {\n";

                foreach ($item as $key => $value) {
                    $hcl .= "  {$key} = {$this->formatValue($value)}\n";
                }

                $hcl .= "}\n\n";
            }
        }

        foreach ($this->resources as $type => $resourceItems) {
            foreach ($resourceItems as $item) {
                $name = $item['name'];
                unset($item['name']);
                $hcl .= "resource \"{$type}\" \"{$name}\" {\n";

                foreach ($item as $key => $value) {
                    $hcl .= $this->formatResourceAttribute($key, $value);
                }

                $hcl .= "}\n\n";
            }
        }

        foreach ($this->outputs as $name => $config) {
            $hcl .= "output \"{$name}\" {\n  description = \"{$config['description']}\"\n  value       = {$config['value']}\n}\n\n";
        }

        return ['hcl' => $hcl];
    }

    private function formatValue($value): string
    {
        if (is_array($value)) {
            if (isset($value[0])) {
                return '[' . implode(', ', array_map(fn($v) => "\"{$v}\"", $value)) . ']';
            }

            $items = [];

            foreach ($value as $k => $v) {
                $items[] = "{$k} = \"{$v}\"";
            }

            return '{' . implode(', ', $items) . '}';
        }

        return "\"{$value}\"";
    }

    private function formatResourceAttribute(string $key, $value): string
    {
        if (is_array($value) && !isset($value[0])) {
            $indent = '  ';
            $lines = "{$indent}{$key} = {\n";

            foreach ($value as $k => $v) {
                $lines .= "{$indent}  {$k} = {$this->formatValue($v)}\n";
            }

            return $lines . "{$indent}}\n";
        }

        if (is_array($value) && isset($value[0]) && is_array($value[0])) {
            $indent = '  ';
            $lines = "{$indent}{$key} = [\n";

            foreach ($value as $item) {
                $lines .= "{$indent}  {\n";

                foreach ($item as $k => $v) {
                    $lines .= "{$indent}    {$k} = {$this->formatValue($v)}\n";
                }

                $lines .= "{$indent}  },\n";
            }

            return $lines . "{$indent}]\n";
        }

        return "  {$key} = {$this->formatValue($value)}\n";
    }

    public function save(string $directory): void
    {
        $hcl = $this->build()['hcl'];

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents("{$directory}/main.tf", $hcl);
    }
}
