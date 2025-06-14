import json

files = ['blue.json', 'rhfsimoes.json']
users = set()
for fname in files:
    with open(fname) as f:
        users.update(json.load(f))

with open('all_users.json', 'w') as out:
    json.dump(sorted(users), out, indent=2)
    out.write('\n')
