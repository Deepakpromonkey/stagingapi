<h2>New Contact Request</h2>
<p><strong>Name:</strong> {{ $contactRequest->first_name }} {{ $contactRequest->last_name }}</p>
<p><strong>Email:</strong> {{ $contactRequest->email }}</p>
<p><strong>Phone:</strong> {{ $contactRequest->phone_country_code }} {{ $contactRequest->phone }}</p>
<p><strong>Job Title:</strong> {{ $contactRequest->job_title }}</p>
<p><strong>Company:</strong> {{ $contactRequest->company }}</p>
<p><strong>Country:</strong> {{ $contactRequest->country }}</p>
<p><strong>Business Type:</strong> {{ $contactRequest->business_type }}</p>
<p><strong>Features of Interest:</strong> 
    {{ is_array($contactRequest->features_of_interest) ? implode(', ', $contactRequest->features_of_interest) : 'None' }}
</p>
<p><strong>Heard About Us:</strong> {{ $contactRequest->hear_about_us }}</p>
<p><strong>Subscribed to Updates:</strong> {{ $contactRequest->subscribe_updates ? 'Yes' : 'No' }}</p>

<h3>Message:</h3>
<p>{{ $contactRequest->message }}</p>