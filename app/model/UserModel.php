<?php
/**
 * this is model layer. what so called Data logic layer
 * classes in this layer shall be extended from relevant classes in Table layer
 * classes in this layer  will be called from controller layer
 */
namespace App\Model;

use App\Table\UserTable;
use Gemvc\Helper\CryptHelper;
use Gemvc\Http\JsonResponse;
use Gemvc\Http\Response;

class UserModel extends UserTable
{
    private ?string $_message;

    public function __construct()
    {
        parent::__construct();
        $this->_message = null;
    }

    public function getMessage(): ?string
    {
        return $this->_message;
    }

    /**
     * Create new User
     * 
     * @return JsonResponse
     */
    public function createModel(): JsonResponse
    {
        $this->email = strtolower($this->email);
        $found = $this->selectByEmail($this->email);
        if ($found) {
            return Response::unprocessableEntity("User already exists");
        
        }
        $this->setPassword($this->password);

        $success = $this->insertSingleQuery();
        if ($this->getError()) {
            return Response::internalError(  $this->getError());
        }
        $this->_message = "User created successfully";
        return Response::created($this, 1, $this->getMessage());
    }

    /**
     * Get User by ID
     * 
     * @return JsonResponse
     */
    public function readModel(): JsonResponse
    {
        $item = $this->selectById($this->id);
        if (!$item) {
            return Response::notFound("User not found");
        }
        $item->password = "-";
        return Response::success($item, 1, "User retrieved successfully");
    }

    /**
     * Update existing User
     * 
     * @return JsonResponse
     */
    public function updateModel(): JsonResponse
    {
        $item = $this->selectById($this->id);
        if (!$item) {
            return Response::notFound("User not found");
        }
        $success = $this->updateSingleQuery();
        if ($this->getError()) {
            return Response::internalError("Failed to update User: " . $this->getError());
        }
        return Response::updated($success, 1, "User updated successfully");
    }

    /**
     * Delete User
     * 
     * @return JsonResponse
     */
    public function deleteModel(): JsonResponse
    {
        $item = $this->selectById($this->id);
        if (!$item) {
            return Response::notFound("User not found");
        }
        $success = $this->deleteByIdQuery($this->id);
        if ($this->getError()) {
            return Response::internalError("Failed to delete User: " . $this->getError());
        }
        return Response::deleted($success, 1, "User deleted successfully");
    }

    public function setPassword(string $plainPassword): void
    {
        $this->password = CryptHelper::hashPassword($plainPassword);
    }

    public function getPassword(): string
    {
        return $this->password;
    }
} 