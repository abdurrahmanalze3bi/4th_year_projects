<?php

namespace App\Http\Controllers\API;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Interfaces\ChatRepositoryInterface;
use App\Services\MessageTypes\MessageTypeFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ChatController extends Controller
{
    private ChatRepositoryInterface $chatRepository;
    private MessageTypeFactory $messageTypeFactory;

    public function __construct(ChatRepositoryInterface $chatRepository, MessageTypeFactory $messageTypeFactory)
    {
        $this->chatRepository = $chatRepository;
        $this->messageTypeFactory = $messageTypeFactory;
    }

    /**
     * Get user's conversations
     */
    public function getConversations(Request $request)
    {
        try {
            $conversations = $this->chatRepository->getUserConversations($request->user());

            return response()->json([
                'success' => true,
                'data' => $conversations->map(function ($conversation) use ($request) {
                    $otherParticipant = $conversation->getOtherParticipant($request->user());
                    $lastMessage = $conversation->lastMessage->first();

                    // Get profile photo for other participant
                    $profilePhoto = null;
                    if ($otherParticipant) {
                        $profile = \App\Models\Profile::where('user_id', $otherParticipant->id)->first();
                        if ($profile && $profile->profile_photo) {
                            // Check if file exists before creating URL
                            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($profile->profile_photo)) {
                                $profilePhoto = asset('storage/' . $profile->profile_photo);
                            }
                        }
                    }

                    return [
                        'id' => $conversation->id,
                        'type' => $conversation->type,
                        'title' => $conversation->title,
                        'other_participant' => $otherParticipant ? [
                            'id' => $otherParticipant->id,
                            'name' => $otherParticipant->first_name . ' ' . $otherParticipant->last_name,
                            'profile_photo' => $profilePhoto,
                        ] : null,
                        'last_message' => $lastMessage ? [
                            'content' => $lastMessage->type === 'image'
                                ? asset('storage/' . $lastMessage->content)  // Show full image URL
                                : $lastMessage->content,
                            'sender_name' => $lastMessage->sender->first_name,
                            'created_at' => $lastMessage->created_at->diffForHumans(),
                        ] : null,
                        'updated_at' => $conversation->updated_at->toIso8601String(),
                    ];
                })
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch conversations: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start a conversation with another user
     */
    public function startConversation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id|different:' . $request->user()->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $currentUser = $request->user();
            $otherUser = \App\Models\User::find($request->user_id);

            // Check if conversation already exists
            $existingConversation = $this->chatRepository->findPrivateConversation($currentUser, $otherUser);

            if ($existingConversation) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'conversation_id' => $existingConversation->id,
                        'message' => 'Conversation already exists'
                    ]
                ]);
            }

            // Create new conversation
            $conversation = $this->chatRepository->createConversation(
                [$currentUser->id, $otherUser->id],
                'private'
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'conversation_id' => $conversation->id,
                    'message' => 'Conversation created successfully'
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create conversation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get messages from a conversation
     */
    public function getMessages(Request $request, int $conversationId)
    {
        try {
            $conversation = $this->chatRepository->findConversation($conversationId);

            if (!$conversation || !$conversation->isParticipant($request->user())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found or access denied'
                ], 404);
            }

            $page = $request->get('page', 1);
            $limit = 50;
            $offset = ($page - 1) * $limit;

            $messages = $this->chatRepository->getMessages($conversationId, $limit, $offset);

            return response()->json([
                'success' => true,
                'data' => $messages->map(function ($message) {
                    // Get profile photo for message sender
                    $profilePhoto = null;
                    $profile = \App\Models\Profile::where('user_id', $message->sender->id)->first();
                    if ($profile && $profile->profile_photo) {
                        // Check if file exists before creating URL
                        if (\Illuminate\Support\Facades\Storage::disk('public')->exists($profile->profile_photo)) {
                            $profilePhoto = asset('storage/' . $profile->profile_photo);
                        }
                    }

                    return [
                        'id' => $message->id,
                        'sender' => [
                            'id' => $message->sender->id,
                            'name' => $message->sender->first_name . ' ' . $message->sender->last_name,
                            'profile_photo' => $profilePhoto,
                        ],
                        'type' => $message->type,
                        'content' => $message->type === 'image'
                            ? asset('storage/' . $message->content)  // Show full image URL for images
                            : $message->content,
                        'metadata' => $message->metadata,
                        'created_at' => $message->created_at->toIso8601String(),
                        'is_edited' => $message->is_edited,
                    ];
                })
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch messages: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send a message
     */
    public function sendMessage(Request $request, int $conversationId)
    {
        try {
            $conversation = $this->chatRepository->findConversation($conversationId);

            if (!$conversation || !$conversation->isParticipant($request->user())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found or access denied'
                ], 404);
            }

            $messageType = $request->get('type', 'text');

            // Validate message type
            if (!in_array($messageType, $this->messageTypeFactory->getAvailableTypes())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid message type'
                ], 422);
            }

            $handler = $this->messageTypeFactory->create($messageType);

            if (!$handler->validate($request->all())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid message data'
                ], 422);
            }

            /* -----------------------------------------------------------------
             *  IMAGE HANDLING
             * ----------------------------------------------------------------- */
            $content = $request->input('content');
            $metadata = $request->input('metadata', []);

            if ($messageType === 'image' && $request->hasFile('image')) {
                $senderId = $request->user()->id;
                $receiverId = $conversation->participants()
                    ->where('user_id', '!=', $senderId)
                    ->value('user_id');

                $ext = $request->file('image')->getClientOriginalExtension();
                $filename = "{$senderId}_{$receiverId}_" . now()->timestamp . ".{$ext}";

                $path = $request->file('image')
                    ->storeAs('chat-images', $filename, 'public');

                $content = $path; // store path in messages.content
                $metadata = array_merge($metadata, [
                    'image_url' => asset("storage/{$path}"),
                    'image_name' => $request->file('image')->getClientOriginalName(),
                    'image_size' => $request->file('image')->getSize(),
                    'image_mime' => $request->file('image')->getMimeType(),
                ]);
            } else {
                $processed = $handler->process($request->all());
                $content = $processed['content'];
                $metadata = array_merge($metadata, $processed['metadata']);
            }

            /* ----------------------------------------------------------------- */

            $message = $this->chatRepository->sendMessage(
                $conversationId,
                $request->user()->id,
                $content,
                $messageType,
                $metadata
            );

            broadcast(new MessageSent($message));

            // Get profile photo for response
            $profilePhoto = null;
            $profile = \App\Models\Profile::where('user_id', $message->sender->id)->first();
            if ($profile && $profile->profile_photo) {
                // Check if file exists before creating URL
                if (\Illuminate\Support\Facades\Storage::disk('public')->exists($profile->profile_photo)) {
                    $profilePhoto = asset('storage/' . $profile->profile_photo);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $message->id,
                    'conversation_id' => $message->conversation_id,
                    'sender' => [
                        'id' => $message->sender->id,
                        'name' => $message->sender->first_name . ' ' . $message->sender->last_name,
                        'profile_photo' => $profilePhoto,
                    ],
                    'type' => $message->type,
                    'content' => $message->type === 'image'
                        ? asset('storage/' . $message->content)  // Show full image URL for images
                        : $message->content,
                    'metadata' => $message->metadata,
                    'created_at' => $message->created_at->toIso8601String(),
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a message
     */
    public function deleteMessage(Request $request, int $messageId)
    {
        try {
            $success = $this->chatRepository->deleteMessage($messageId, $request->user()->id);

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Message not found or permission denied'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Message deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete message: ' . $e->getMessage()
            ], 500);
        }
    }
}
