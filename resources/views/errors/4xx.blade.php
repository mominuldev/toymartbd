@extends('errors::layout')

@section('code', (string) $exception->getStatusCode())
@section('title', 'This request could not be completed')
@section('message', 'Go back and try again. If you were signed in, your session may have expired.')
@section('title_bn', 'অনুরোধটি সম্পন্ন করা যায়নি')
@section('message_bn', 'পেছনে গিয়ে আবার চেষ্টা করুন। সাইন ইন করা থাকলে সেশনের মেয়াদ শেষ হয়ে থাকতে পারে।')
@section('actions')
    <a class="button" href="{{ url('/admin') }}">Admin dashboard · <span lang="bn" class="lang-bn">ড্যাশবোর্ড</span></a>
@endsection
