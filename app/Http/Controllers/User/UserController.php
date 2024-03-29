<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\ApiController;
use App\Mail\UserCreated;
use App\Models\User;
use App\Transformers\UserTransformer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class UserController extends ApiController
{

    public function __construct()
    {
     
        $this->middleware('client.credentials')->only(['store', 'resend']); 

        $this->middleware('transform.input:'.UserTransformer::class)->only(['store', 'update']);
    }
   
    public function index()
    {
        $users = User::all();
        return $this->showAll($users);
    
    }

    public function store(Request $request)
    {
        $rules = [
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6|confirmed'
        ];

        $this->validate($request, $rules);
        $data = $request->all();
        $data['password'] = bcrypt($request->password);
        $data['verified'] = User::UNVERIFIED_USER;
        $data['verification_token'] = User::generateVerificationCode();
        $data['admin'] = User::REGULAR_USER;

        $user = User::create($data);

        return $this->showOne($user);
    }

    public function show(User $user)
    {
        return $this->showOne($user);
    }


    public function update(Request $request, User $user)
    {
        
        $rules = [
            'email'     =>  'email|unique:users,email,'.$user->id,
            'password'  =>  'min:6|confirmed',
            'admin'     =>  'in:'. User::ADMIN_USER .',' .User::REGULAR_USER
        ];

        $this->validate($request, $rules);

        if($request->has('name')){
            $user->name = $request->name;
        }

        if($request->has('email') && $user->email != $request->email){
            $user->verified = User::UNVERIFIED_USER;
            $user->verification_token = User::generateVerificationCode();
            $user->email = $request->email;
           
        }

        if($request->has('password')){
            $user->password = bcrypt($request->password);
        }

        if($request->has('admin')){
            if(!$user->isVerified()){
                return $this->errorResponse('Only verified users can modify the admin field!', 409);
            }

            $user->admin = $request->admin;
        }

        if(!$user->isDirty()){
            return $this->errorResponse('You need to specify a different value to update!', 422);
            
        }

        $user->save();

        return $this->showOne($user, 200);
    }

 
    public function destroy(User $user)
    {
        $user->delete();
        return $this->showOne($user, 200);
    }


    public function verify($token){
        $user = User::where('verification_token', $token)->firstOrFail();
        $user->verified = User::VERIFIED_USER;
        $user->verification_token = null;

        $user->save();

        return $this->showMessage('The account has been verified successfully.');
    }


    public function resend(User $user){
        if($user->isVerified()){
            return $this->errorResponse('This user is already verified', 409);
        }

        retry(5, function() use ($user){
            Mail::to($user)->send(new UserCreated($user));
        });
       
        return $this->showMessage('The verification email has been resend.');
    }
}
