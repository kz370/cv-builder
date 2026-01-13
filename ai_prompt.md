# AI CV Import Prompt

Use the following prompt to convert your existing CV/Resume text into a JSON format compatible with this CV Builder.

---

**Prompt:**

"I need you to convert my CV/Resume text into a specific JSON format compatible with a custom CV builder.

Here is the JSON structure you MUST follow. Please populate it with the information from my CV text provided below.

**JSON Structure:**

```json
{
  "meta": {
    "header_name": "Full Name",
    "header_role": "Current Job Title",
    "header_location": "City, Country",
    "header_email": "email@example.com",
    "header_phone": "+1 234 567 890",
    "header_linkedin": "linkedin.com/in/username",
    "profile_summary": "A brief professional summary...",
    "section_order": "[\"profile\",\"skills\",\"experience\",\"packages\",\"education\",\"certifications\",\"languages\"]"
  },
  "skills": [
    { "id": 1, "category": "Languages", "content": "PHP, JavaScript, Python", "sort_order": 0 },
    { "id": 2, "category": "Frameworks", "content": "Laravel, Vue.js, React", "sort_order": 1 }
  ],
  "experience": [
    { "id": 1, "role": "Job Title", "company": "Company Name", "location": "City, Country", "date_range": "Jan 2020 - Present", "sort_order": 0 },
    { "id": 2, "role": "Previous Job", "company": "Old Company", "location": "City, Country", "date_range": "Jan 2018 - Dec 2019", "sort_order": 1 }
  ],
  "experience_items": [
    { "id": 1, "experience_id": 1, "item_type": "bullet", "content": "Description of a responsibility or achievement.", "sort_order": 0 },
    { "id": 2, "experience_id": 1, "item_type": "project", "project_name": "Project Alpha", "content": "Built a CRM system...", "sort_order": 1 },
    { "id": 3, "experience_id": 2, "item_type": "bullet", "content": "accomplishment...", "sort_order": 0 }
  ],
  "packages": [
    { "id": 1, "name": "Project/Package Name", "type": "Open Source", "description": "Brief description.", "url": "https://github.com/...", "sort_order": 0 }
  ],
  "education": [
    { "id": 1, "degree": "Degree Name", "institution": "University/College", "date_range": "2015 - 2019", "sort_order": 0 }
  ],
  "certifications": [
    { "id": 1, "name": "AWS Certified Solutions Architect", "sort_order": 0 }
  ],
  "languages": [
    { "id": 1, "language": "English", "proficiency": "Fluent", "sort_order": 0 },
    { "id": 2, "language": "Spanish", "proficiency": "Intermediate", "sort_order": 1 }
  ],
  "header_extras": [],
  "custom_sections": [
     { "id": 1, "title": "Publications", "content": "List of publications..." }
  ]
}
```

**Rules:**
1. **Ids**: Assign sequential numeric IDs starting from 1 for each array.
2. **Experience Items**: IMPORTANT. The `experience_items` array contains the bullet points for the jobs. You MUST link them to the correct job using `experience_id` which corresponds to the `id` in the `experience` array.
3. **Item Types**: Use `"bullet"` for standard bullet points. Use `"project"` if the bullet point refers to a specific named project, and populate `project_name`.
4. **Skills**: Group skills by category (e.g., Languages, Tools, Frameworks).
5. **JSON Only**: Output ONLY the raw JSON string, no markdown formatting or backticks, so I can copy-paste it directly.

**Instructions:**
Please extract the information from the attached CV file (PDF, Image, or Docx) or the text provided below, and map it to the JSON structure defined above.

**My CV Text (if not attaching a file):**

[PASTE YOUR CV TEXT HERE OR ATTACH YOUR CV FILE]
"
