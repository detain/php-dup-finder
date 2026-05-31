<?php
declare(strict_types=1);

namespace App\User\Grpc;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Grpc\BaseStub;
use Grpc\Channel;
use Grpc\ChannelCredentials;
use Grpc\Timeval;

final class UserServiceClient extends BaseStub
{
    private LoggerInterface $logger;
    private string $host;
    private int $port;
    private Channel $channel;
    private int $timeout = 30000;
    private int $maxRetries = 3;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->host = $config->get('services.user.host', 'localhost');
        $this->port = (int)$config->get('services.user.port', 50051);
        
        $address = $this->host . ':' . $this->port;
        $credentials = ChannelCredentials::createInsecure();
        
        $options = [
            'timeout' => $this->timeout,
        ];
        
        parent::__construct($address, $credentials, $options);
        
        $this->channel = new Channel($address, $credentials, $options);
    }

    public function __destruct()
    {
        $this->channel->close();
    }

    public function getUser(GetUserRequest $request, array $metadata = []): ?UserResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/user.UserService/GetUser',
                $request,
                ['\App\User\Grpc\UserResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('UserService getUser failed', [
                'user_id' => $request->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getUsersByIds(GetUsersByIdsRequest $request, array $metadata = []): UsersResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/user.UserService/GetUsersByIds',
                $request,
                ['\App\User\Grpc\UsersResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('UserService getUsersByIds failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function createUser(CreateUserRequest $request, array $metadata = []): UserResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/user.UserService/CreateUser',
                $request,
                ['\App\User\Grpc\UserResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            $this->logger->info('User created via gRPC', [
                'user_id' => $response->getId(),
            ]);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('UserService createUser failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function updateUser(UpdateUserRequest $request, array $metadata = []): UserResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/user.UserService/UpdateUser',
                $request,
                ['\App\User\Grpc\UserResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            $this->logger->info('User updated via gRPC', [
                'user_id' => $request->getId(),
            ]);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('UserService updateUser failed', [
                'user_id' => $request->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deleteUser(DeleteUserRequest $request, array $metadata = []): DeleteResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/user.UserService/DeleteUser',
                $request,
                ['\App\User\Grpc\DeleteResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            $this->logger->info('User deleted via gRPC', [
                'user_id' => $request->getId(),
            ]);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('UserService deleteUser failed', [
                'user_id' => $request->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function searchUsers(SearchUsersRequest $request, array $metadata = []): UsersResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/user.UserService/SearchUsers',
                $request,
                ['\App\User\Grpc\UsersResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('UserService searchUsers failed', [
                'query' => $request->getQuery(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getUserStats(GetUserStatsRequest $request, array $metadata = []): UserStatsResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/user.UserService/GetUserStats',
                $request,
                ['\App\User\Grpc\UserStatsResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('UserService getUserStats failed', [
                'user_id' => $request->getUserId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function isHealthy(): bool
    {
        try {
            $request = new HealthCheckRequest();
            $request->setService('user.UserService');
            
            $deadline = new Timeval(5000);
            
            $response = $this->_simpleRequest(
                '/grpc.health.v1.Health/Check',
                $request,
                ['\Grpc\HealthCheckResponse', 'decode'],
                [],
                $deadline
            );
            
            return $response->getStatus() === \Grpc\HealthCheckResponse::SERVING;
        } catch (\Exception $e) {
            $this->logger->warning('UserService health check failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
